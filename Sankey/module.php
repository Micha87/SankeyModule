<?php
/**
 * SankeyModule.php - Variante A mit REST-Hook (lokaler JSON-Endpunkt)
 */
class SankeyModule extends IPSModule {

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString('WebPath', '/user/sankey/');
        $this->RegisterPropertyString('PV', '[]');
        $this->RegisterPropertyInteger('Battery', 0);
        $this->RegisterPropertyInteger('Grid', 0);
        $this->RegisterPropertyString('Consumers', '[]');
        $this->RegisterPropertyInteger('UpdateInterval', 30);

        $this->RegisterHook('/hook/sankey/');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();
        $hook = '/hook/sankey/' . $this->InstanceID . '/data';
        $this->RegisterHook($hook);
        $this->SetReceiveDataFilter('.*');
        $this->MaintainTimer('SankeyUpdate', $this->ReadPropertyInteger('UpdateInterval') * 1000, 'SM_Update($_IPS["TARGET"]);');
    }

    public function Update() {
    }

    public function ReceiveData($JSONString) {
        $data = $this->GetSankeyData();
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    private function GetSankeyData(): array {
        $pvList = json_decode($this->ReadPropertyString('PV'), true) ?? [];
        $consumers = json_decode($this->ReadPropertyString('Consumers'), true) ?? [];
        $battery = (int)$this->ReadPropertyInteger('Battery');
        $grid = (int)$this->ReadPropertyInteger('Grid');

        $pv_values = [];
        $pv_total = 0;
        foreach ($pvList as $p) {
            $vid = (int)($p['variable'] ?? 0);
            $name = $p['name'] ?? ('PV ' . $vid);
            $val = max(0, @GetValue($vid));
            $pv_values[] = ['name' => $name, 'value' => $val];
            $pv_total += $val;
        }

        $battv = @GetValue($battery);
        $batt_charge = max(0, $battv);
        $batt_discharge = max(0, -$battv);

        $consv = 0;
        foreach ($consumers as $c) {
            $vid = (int)($c['variable'] ?? 0);
            $consv += max(0, @GetValue($vid));
        }

        $gridv = @GetValue($grid);
        $grid_import = max(0, $gridv);
        $grid_export = max(0, -$gridv);

        $pv_to_consumption = min($pv_total, $consv);
        $remain = $pv_total - $pv_to_consumption;
        $pv_to_battery = min($remain, $batt_charge);
        $remain -= $pv_to_battery;
        $pv_to_grid = max(0, $remain);

        $batt_to_consumption = $batt_discharge;
        $covered_by_pv_and_batt = $pv_to_consumption + $batt_to_consumption;
        $grid_to_consumption = max(0, $consv - $covered_by_pv_and_batt);
        $needed_batt_charge_remaining = max(0, $batt_charge - $pv_to_battery);
        $grid_to_battery = min($grid_import, $needed_batt_charge_remaining);
        $grid_unallocated = max(0, $grid_import - ($grid_to_consumption + $grid_to_battery));

        $rows = [];
        foreach ($pv_values as $p) $rows[] = [$p['name'], 'PV Gesamt', round($p['value'],2)];
        if ($pv_to_consumption>0) $rows[]=['PV Gesamt','Verbraucher (Haus)',round($pv_to_consumption,2)];
        if ($pv_to_battery>0) $rows[]=['PV Gesamt','Batterie (Laden)',round($pv_to_battery,2)];
        if ($pv_to_grid>0) $rows[]=['PV Gesamt','Netz (Einspeisung)',round($pv_to_grid,2)];
        if ($batt_to_consumption>0) $rows[]=['Batterie (Entladen)','Verbraucher (Haus)',round($batt_to_consumption,2)];
        if ($grid_to_consumption>0) $rows[]=['Netz (Bezug)','Verbraucher (Haus)',round($grid_to_consumption,2)];
        if ($grid_to_battery>0) $rows[]=['Netz (Bezug)','Batterie (Laden)',round($grid_to_battery,2)];
        if ($grid_unallocated>0) $rows[]=['Netz (Bezug)','Unallocated',round($grid_unallocated,2)];

        return [
            'timestamp' => date('c'),
            'units' => 'W',
            'values' => ['pv_total'=>$pv_total,'battery'=>$battv,'consumption'=>$consv,'netz'=>$gridv],
            'rows'=>$rows
        ];
    }
}
?>
