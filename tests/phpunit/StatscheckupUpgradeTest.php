<?php

class StatscheckupUpgradeTest extends StatscheckupTestCase
{
    public function testUpgrade202SwitchesHookAndReturnsTrue()
    {
        $this->module->install();
        $pdo = statscheckup_test_pdo();
        $pdo->exec('DELETE FROM ps_hook_module');
        $pdo->exec('DELETE FROM ps_hook');
        $pdo->prepare('INSERT INTO ps_hook (name) VALUES (?)')->execute(array('AdminStatsModules'));
        $idHook = (int) $pdo->lastInsertId();
        $idModule = (int) $pdo->query('SELECT id_module FROM ps_module WHERE name = "statscheckup"')->fetchColumn();
        $pdo->prepare('INSERT INTO ps_hook_module (id_hook, id_module, id_shop) VALUES (?, ?, 1)')
            ->execute(array($idHook, $idModule));

        $this->assertSame(true, upgrade_module_2_0_2($this->module));

        $hooks = $pdo->query(
            'SELECT h.name FROM ps_hook_module hm
            INNER JOIN ps_hook h ON h.id_hook = hm.id_hook
            INNER JOIN ps_module m ON m.id_module = hm.id_module
            WHERE m.name = "statscheckup"'
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('displayAdminStatsModules', $hooks);
        $this->assertNotContains('AdminStatsModules', $hooks);
    }
}
