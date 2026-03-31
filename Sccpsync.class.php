<?php
/**
 * FreePBX BMO Module: sccpsync
 *
 * Синхронизирует sccpline.label → FreePBX при каждом Apply Config.
 *
 * ПРОБЛЕМА которую решает модуль:
 *   FreePBX при открытии/сохранении формы редактирования экстена
 *   использует userman_users.displayname как источник Display Name.
 *   SCCP Manager хранит имя в sccpline.label.
 *   Эти поля не связаны — при редактировании экстена через стандартный
 *   интерфейс FreePBX подставляет в Display Name то что в userman_users.displayname,
 *   а при сохранении перезаписывает users.name этим же значением.
 *
 * РЕШЕНИЕ:
 *   При каждом Apply Config синхронизируем:
 *     sccpline.label → userman_users.displayname  (показывается в форме редактирования)
 *     sccpline.label → users.name                 (используется в dialplan/hints)
 *
 *   Дополнительно: патчим SCCP-драйвер FreePBX Core (Sccp.class.php), чтобы
 *   getDevice() возвращал label AS name вместо name AS name — иначе при открытии
 *   формы редактирования экстена array_merge($device,$tech) перезаписывает $name
 *   номером экстена из sccpline.name (PK).
 *
 * УСЛОВИЕ обновления (защита от затирания ручных изменений):
 *   Обновляем ЕСЛИ sccpline.label != текущего значения в целевом поле.
 *   Исключение: если label пустой или равен номеру — пропускаем.
 *
 * Таблицы:
 *   sccpline       — chan-sccp-b: name(PK=ext#), label, cid_name
 *   devices        — FreePBX:    id(=ext#), tech('sccp'/'sccp_custom'), user(→users.extension)
 *   users          — FreePBX:    extension(PK), name
 *   userman_users  — FreePBX:    id, username(=ext#), default_extension(=ext#), displayname
 */

namespace FreePBX\modules;

class Sccpsync extends \FreePBX_Helpers implements \BMO {

    const LOG_FILE = '/var/log/asterisk/sccpsync.log';

    public function __construct($freepbx = null) {
        if ($freepbx === null) {
            throw new \Exception('Not given a FreePBX Object');
        }
        $this->FreePBX = $freepbx;
        $this->db       = $freepbx->Database;
    }

    // Путь к SCCP-драйверу FreePBX Core
    const SCCP_DRIVER = '/var/www/html/admin/modules/core/functions.inc/drivers/Sccp.class.php';

    // =========================================================
    // BMO Interface
    // =========================================================
    public function install()   { $this->patchSccpDriver(); }
    public function uninstall() {}
    public function backup()             {}
    public function restore($backup)     {}
    public function doConfigPageInit($p) {}
    public function search($q, &$r)      {}

    // =========================================================
    // BMO Hook: delDevice
    // Вызывается Core при удалении/редактировании device.
    // При editmode=true (редактирование) сохраняем привязки телефонов
    // и восстанавливаем их после завершения HTTP-запроса (когда addDevice
    // уже выполнен и sccpline пересоздана).
    // =========================================================
    public function delDevice($account, $editmode = false) {
        if (!$editmode) {
            // Реальное удаление — ничего не делаем, пусть SCCP-драйвер
            // сам почистит sccpbuttonconfig
            return;
        }

        // Редактирование — сохраняем текущие привязки телефонов
        $stmt = $this->db->prepare(
            "SELECT * FROM sccpbuttonconfig WHERE name = ? AND buttontype = 'line'"
        );
        $stmt->execute([$account]);
        $buttons = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($buttons)) {
            $this->_log("sccpsync: delDevice ext={$account} editmode=true, no buttons to save");
            return;
        }

        $this->_log("sccpsync: delDevice ext={$account} editmode=true, saved " . count($buttons) . " button(s)");

        // Восстанавливаем через shutdown_function — к тому моменту
        // addDevice уже выполнен и sccpline пересоздана
        $db  = $this->db;
        $log = function($msg) { $this->_log($msg); };

        register_shutdown_function(function() use ($account, $buttons, $db, $log) {
            // Проверяем что линия существует (значит это было редактирование)
            $check = $db->prepare("SELECT name FROM sccpline WHERE name = ?");
            $check->execute([$account]);
            if (!$check->fetch()) {
                $log("sccpsync: shutdown ext={$account}: sccpline not found, skip restore");
                return;
            }

            // Удаляем то что могло появиться после нашего snapshot
            $del = $db->prepare("DELETE FROM sccpbuttonconfig WHERE name = ? AND buttontype = 'line'");
            $del->execute([$account]);

            // Восстанавливаем сохранённые привязки
            $ins = $db->prepare(
                "INSERT IGNORE INTO sccpbuttonconfig (ref, reftype, instance, buttontype, name, options) VALUES (?, ?, ?, ?, ?, ?)"
            );
            foreach ($buttons as $btn) {
                $ins->execute([
                    $btn['ref'],
                    $btn['reftype'],
                    $btn['instance'],
                    $btn['buttontype'],
                    $btn['name'],
                    $btn['options'],
                ]);
            }
            $log("sccpsync: shutdown ext={$account}: restored " . count($buttons) . " button(s)");
        });
    }

    // =========================================================
    // BMO DialplanHook
    // Приоритет 900 — после core (480), перед финальной записью конфигов
    // =========================================================
    public function myDialplanHooks() {
        return 900;
    }

    public function doDialplanHook(&$ext, $engine, $priority) {
        if ($engine !== 'asterisk') {
            return;
        }
        $this->patchSccpDriver();
        $this->syncLabels();
    }

    // =========================================================
    // Патч SCCP-драйвера
    // =========================================================

    /**
     * Патчит Sccp.class.php (SCCP-драйвер FreePBX Core), заменяя
     *   "name AS name" → "label AS name"
     * в методе getDevice(), чтобы форма редактирования экстена показывала
     * label (имя) вместо name (номер экстена = PK sccpline).
     *
     * Вызывается при install() и при каждом Apply Config (doDialplanHook).
     * Идемпотентен: повторный вызов ничего не меняет если патч уже применён.
     */
    public function patchSccpDriver() {
        // Ищем реальный файл: либо прямо Sccp.class.php, либо через include
        $driverFile = self::SCCP_DRIVER;

        if (!file_exists($driverFile)) {
            $this->_log('sccpsync: patchSccpDriver: driver file not found, skip');
            return;
        }

        // Читаем содержимое (файл-обёртка содержит include на .v433)
        $content = file_get_contents($driverFile);

        // Определяем реальный файл класса (может быть include на .v433)
        $realFile = $driverFile;
        if (preg_match('/include\s+[\'\"](.*?)[\'\"]/', $content, $m)) {
            if (file_exists($m[1])) {
                $realFile = $m[1];
                $content  = file_get_contents($realFile);
            }
        }

        $needle      = '"SELECT name AS id, name AS name "';
        $replacement = '"SELECT name AS id, label AS name "';

        if (strpos($content, $needle) === false) {
            // Уже пропатчен или строка не найдена
            if (strpos($content, $replacement) !== false) {
                $this->_log('sccpsync: patchSccpDriver: already patched');
            } else {
                $this->_log('sccpsync: patchSccpDriver: target string not found in ' . $realFile);
            }
            return;
        }

        $patched = str_replace($needle, $replacement, $content);
        if (file_put_contents($realFile, $patched) === false) {
            $this->_log('sccpsync: patchSccpDriver: FAILED to write ' . $realFile);
            return;
        }

        $this->_log('sccpsync: patchSccpDriver: patched ' . $realFile . ' (name AS name → label AS name)');
    }

    // =========================================================
    // Основная синхронизация
    // =========================================================

    /**
     * Синхронизирует sccpline.label в оба поля FreePBX:
     *   → userman_users.displayname  (что видит admin в форме редактирования)
     *   → users.name                 (что используется в dialplan/hints/CDR)
     *
     * Обновляет запись если label отличается от текущего значения в целевом поле.
     * Пропускает если label пустой или равен номеру экстена.
     */
    public function syncLabels() {
        $this->_log('=== sccpsync: syncLabels() start ===');

        if (!$this->_tableExists('sccpline')) {
            $this->_log('sccpsync: table sccpline not found, skipping');
            return;
        }

        $rows = $this->_getSccpExtensions();

        if (empty($rows)) {
            $this->_log('sccpsync: no SCCP extensions found');
            return;
        }

        $updatedUsers   = 0;
        $updatedUserman = 0;
        $skipped        = 0;

        foreach ($rows as $row) {
            $ext           = $row['extension'];
            $label         = trim($row['sccp_label']        ?? '');
            $users_name    = trim($row['users_name']        ?? '');
            $display_name  = trim($row['userman_displayname'] ?? '');
            $cid_name      = trim($row['sccp_cid_name']     ?? '');

            // Пропускаем если label пустой или равен номеру
            if ($label === '' || $label === $ext) {
                $skipped++;
                $this->_log("sccpsync: SKIP ext={$ext} label='{$label}' (empty or numeric)");
                continue;
            }

            // 1. Синхронизируем userman_users.displayname ← label
            if ($display_name !== $label) {
                $this->_updateDisplayName($ext, $label);
                $updatedUserman++;
                $this->_log("sccpsync: userman ext={$ext} '{$display_name}' → '{$label}'");
            }

            // 2. Синхронизируем users.name ← label
            if ($users_name !== $label) {
                $this->_updateUserName($ext, $label);
                $updatedUsers++;
                $this->_log("sccpsync: users ext={$ext} '{$users_name}' → '{$label}'");
            }

            // 3. Синхронизируем sccpline.cid_name ← label (если пустой)
            if ($cid_name === '') {
                $this->_syncCidName($ext, $label);
                $this->_log("sccpsync: cid_name ext={$ext} → '{$label}'");
            }
        }

        $total = max($updatedUsers, $updatedUserman);
        $this->_log("sccpsync: done. userman={$updatedUserman} users={$updatedUsers} skipped={$skipped}");

        // Выводим в stdout fwconsole (out() доступна только в веб-контексте,
        // поэтому используем echo с переводом строки — это безопасно в CLI)
        if ($total > 0) {
            echo "sccpsync: synced {$total} SCCP display name(s) from sccpline.label" . PHP_EOL;
        }
    }

    // =========================================================
    // Публичный API
    // =========================================================

    /**
     * Принудительная синхронизация конкретного экстена.
     * fwconsole sccpsync --ext=3005
     */
    public function syncExtension($extNum) {
        if (!$this->_tableExists('sccpline')) {
            return ['updated' => false, 'error' => 'sccpline table not found'];
        }

        $stmt = $this->db->prepare(
            "SELECT
                s.name            AS extension,
                s.label           AS sccp_label,
                s.cid_name        AS sccp_cid_name,
                u.name            AS users_name,
                uu.displayname    AS userman_displayname
             FROM sccpline s
             JOIN devices d  ON d.id   = s.name
                             AND d.tech IN ('sccp','sccp_custom')
             JOIN users u    ON u.extension = d.user
             LEFT JOIN userman_users uu ON uu.default_extension = s.name
             WHERE s.name = ?"
        );
        $stmt->execute([$extNum]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return ['updated' => false, 'error' => "SCCP extension {$extNum} not found"];
        }

        $label        = trim($row['sccp_label'] ?? '');
        $users_name   = trim($row['users_name'] ?? '');
        $display_name = trim($row['userman_displayname'] ?? '');

        if ($label === '' || $label === $extNum) {
            return ['updated' => false,
                    'reason'  => 'sccp label empty or equals extension number',
                    'label'   => $label];
        }

        $changes = [];
        if ($display_name !== $label) {
            $this->_updateDisplayName($extNum, $label);
            $changes['userman_displayname'] = ['from' => $display_name, 'to' => $label];
        }
        if ($users_name !== $label) {
            $this->_updateUserName($extNum, $label);
            $changes['users_name'] = ['from' => $users_name, 'to' => $label];
        }
        if (trim($row['sccp_cid_name'] ?? '') === '') {
            $this->_syncCidName($extNum, $label);
            $changes['cid_name'] = ['from' => '', 'to' => $label];
        }

        return ['updated' => !empty($changes), 'changes' => $changes];
    }

    /**
     * Возвращает список всех SCCP-экстенов и состояние синхронизации.
     */
    public function getSyncStatus() {
        if (!$this->_tableExists('sccpline')) {
            return [];
        }
        return $this->_getSccpExtensions();
    }

    // =========================================================
    // Приватные методы
    // =========================================================

    /**
     * Получает все SCCP-экстены со всеми нужными полями из трёх таблиц.
     */
    private function _getSccpExtensions() {
        $stmt = $this->db->prepare(
            "SELECT
                s.name              AS extension,
                s.label             AS sccp_label,
                s.cid_name          AS sccp_cid_name,
                u.name              AS users_name,
                uu.displayname      AS userman_displayname,
                d.description       AS device_desc
             FROM sccpline s
             JOIN devices d       ON d.id   = s.name
                                 AND d.tech IN ('sccp', 'sccp_custom')
             JOIN users u         ON u.extension = d.user
             LEFT JOIN userman_users uu ON uu.default_extension = s.name
             ORDER BY s.name"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Обновляет userman_users.displayname — это поле отображается
     * в форме редактирования экстена FreePBX (поле Display Name).
     */
    private function _updateDisplayName($ext, $name) {
        $stmt = $this->db->prepare(
            "UPDATE userman_users SET displayname = ? WHERE default_extension = ?"
        );
        $stmt->execute([$name, $ext]);
    }

    /**
     * Обновляет users.name — используется в dialplan, hints, CDR.
     */
    private function _updateUserName($ext, $name) {
        $stmt = $this->db->prepare(
            "UPDATE users SET name = ? WHERE extension = ?"
        );
        $stmt->execute([$name, $ext]);
    }

    /**
     * Если sccpline.cid_name пустое — заполняем label-ом.
     */
    private function _syncCidName($ext, $label) {
        $stmt = $this->db->prepare(
            "UPDATE sccpline SET cid_name = ? WHERE name = ? AND (cid_name IS NULL OR cid_name = '')"
        );
        $stmt->execute([$label, $ext]);
    }

    private function _tableExists($table) {
        try {
            $this->db->query("SELECT 1 FROM `{$table}` LIMIT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function _log($message) {
        $line = date('[Y-m-d H:i:s]') . ' ' . $message . PHP_EOL;
        @file_put_contents(self::LOG_FILE, $line, FILE_APPEND | LOCK_EX);
    }
}
