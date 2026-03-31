<?php
/**
 * sccpsync — Console/Sccpsync.class.php
 *
 * fwconsole sccpsync [--dry-run] [--ext=НОМЕР] [--status] [--force]
 *
 *   fwconsole sccpsync             — синхронизировать все (только расхождения)
 *   fwconsole sccpsync --force     — синхронизировать все безусловно
 *   fwconsole sccpsync --dry-run   — показать что изменится без записи
 *   fwconsole sccpsync --ext=3005  — только один экстен
 *   fwconsole sccpsync --status    — таблица текущего состояния
 */

namespace FreePBX\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Sccpsync extends Command {

    protected function configure() {
        $this->setName('sccpsync')
             ->setDescription('Sync sccpline.label → FreePBX Display Name (userman + users) for SCCP extensions')
             ->addOption('dry-run', null, InputOption::VALUE_NONE,   'Show changes without writing')
             ->addOption('force',   null, InputOption::VALUE_NONE,   'Update all regardless of current value')
             ->addOption('ext',     null, InputOption::VALUE_REQUIRED,'Sync only this extension number')
             ->addOption('status',  null, InputOption::VALUE_NONE,   'Show current sync status table');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $freepbx = \FreePBX::Create();
        $module  = $freepbx->Sccpsync;

        // --status
        if ($input->getOption('status')) {
            $rows = $module->getSyncStatus();
            if (empty($rows)) {
                $output->writeln('<comment>No SCCP extensions found</comment>');
                return 0;
            }
            $fmt = '  %-6s %-34s %-34s %-34s %s';
            $output->writeln('<info>SCCP Label Sync Status:</info>');
            $output->writeln(sprintf($fmt, 'Ext', 'sccpline.label', 'userman.displayname', 'users.name', 'State'));
            $output->writeln(str_repeat('-', 120));

            foreach ($rows as $r) {
                $ext     = $r['extension'];
                $label   = $r['sccp_label']             ?? '';
                $disp    = $r['userman_displayname']    ?? '';
                $uname   = $r['users_name']             ?? '';

                $labelOk = ($label === $disp);
                $nameOk  = ($label === $uname);

                if ($labelOk && $nameOk) {
                    $state = '<info>OK</info>';
                } elseif ($label === '' || $label === $ext) {
                    $state = '<comment>NO LABEL</comment>';
                } else {
                    $parts = [];
                    if (!$labelOk) $parts[] = 'userman';
                    if (!$nameOk)  $parts[] = 'users';
                    $state = '<fg=yellow>NEEDS SYNC (' . implode('+', $parts) . ')</fg=yellow>';
                }

                $output->writeln(sprintf($fmt,
                    $ext,
                    mb_substr($label, 0, 32),
                    mb_substr($disp,  0, 32),
                    mb_substr($uname, 0, 32),
                    $state
                ));
            }
            return 0;
        }

        // --ext=НОМЕР
        if ($extArg = $input->getOption('ext')) {
            if ($input->getOption('dry-run')) {
                $output->writeln("<comment>[dry-run] Would sync ext={$extArg}</comment>");
                return 0;
            }
            $result = $module->syncExtension($extArg);
            if (!empty($result['error'])) {
                $output->writeln("<error>{$result['error']}</error>");
                return 1;
            }
            if ($result['updated']) {
                foreach ($result['changes'] as $field => $change) {
                    $output->writeln(
                        "<info>  {$field}: '{$change['from']}' → '{$change['to']}'</info>"
                    );
                }
            } else {
                $output->writeln(
                    "<comment>No changes needed for ext {$extArg}" .
                    (!empty($result['reason']) ? " ({$result['reason']})" : '') .
                    "</comment>"
                );
            }
            return 0;
        }

        // --dry-run (всё)
        if ($input->getOption('dry-run')) {
            $rows  = $module->getSyncStatus();
            $count = 0;
            $output->writeln('<comment>[dry-run] Would update:</comment>');
            foreach ($rows as $r) {
                $ext   = $r['extension'];
                $label = trim($r['sccp_label']          ?? '');
                $disp  = trim($r['userman_displayname'] ?? '');
                $uname = trim($r['users_name']          ?? '');
                if ($label === '' || $label === $ext) continue;
                if ($label !== $disp || $label !== $uname) {
                    $output->writeln("  ext={$ext}  userman='{$disp}' users='{$uname}' → '{$label}'");
                    $count++;
                }
            }
            $output->writeln("<comment>[dry-run] {$count} extension(s) would be updated</comment>");
            return 0;
        }

        // Обычный запуск
        $output->writeln('<info>sccpsync: starting sync...</info>');
        $module->syncLabels();
        $output->writeln('<info>sccpsync: done. See /var/log/asterisk/sccpsync.log</info>');
        return 0;
    }
}
