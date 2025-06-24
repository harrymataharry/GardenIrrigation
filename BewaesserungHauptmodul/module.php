<?php

class BewaesserungHauptmodul extends IPSModule
{
    public function Create()
    {
        parent::Create();
        // --- EIGENSCHAFTEN ---
        $this->RegisterPropertyInteger('PowerEnergyID', 0);
        $this->RegisterPropertyInteger('PowerStateID', 0);
        $this->RegisterPropertyInteger('ArchiveID', 0);
        $this->RegisterPropertyInteger('RainVariableID', 0);
        $this->RegisterPropertyInteger('RainThreshold', 10);
        $this->RegisterPropertyInteger('LookbackHours', 72);

        // --- OBJEKTE ERSTELLEN ---
        
        // --- KORREKTUR: KATEGORIEN MIT STANDARD-FUNKTIONEN ERSTELLEN ---
        // Erstellt eine Kategorie und weist ihr einen eindeutigen "Ident" zu
        if (@IPS_GetObjectIDByIdent('SteuerungUndZeitplan', $this->InstanceID) === false) {
            $catID = IPS_CreateCategory();
            IPS_SetName($catID, 'Steuerung & Zeitplan');
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, 'SteuerungUndZeitplan');
        }
        if (@IPS_GetObjectIDByIdent('Alarme', $this->InstanceID) === false) {
            $catID = IPS_CreateCategory();
            IPS_SetName($catID, 'Alarme');
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, 'Alarme');
        }
        
        // --- RESTLICHE OBJEKTE ERSTELLEN (unverändert) ---
        $this->RegisterVariableBoolean('GlobalActiveSwitch', 'Bewässerung aktiv', '~Switch', 10);
        $this->EnableAction('GlobalActiveSwitch');
        
        if (!IPS_VariableProfileExists('BEW.Rainfall.lpm2')) {
            IPS_CreateVariableProfile('BEW.Rainfall.lpm2', 2); // 2 = Float
            IPS_SetVariableProfileText('BEW.Rainfall.lpm2', '', ' l/m²');
            IPS_SetVariableProfileDigits('BEW.Rainfall.lpm2', 2);
        }
        $this->RegisterVariableFloat('RainfallLastPeriod', 'Regenmenge im Prüfzeitraum', 'BEW.Rainfall.lpm2', 20);

        $this->RegisterEvent('WeeklyPlan', 'Bewässerungsplan', 2, 30); // Typ 2 = Wochenplan
        IPS_SetEventScheduleAction($this->GetIDForIdent('WeeklyPlan'), 0, 'Bewässerung starten', 0x0000FF, 'BEW_StartCycle(' . $this->InstanceID . ');');

        $this->RegisterVariableBoolean('AlarmActive', 'Alarm aktiv', '~Alert', 100);
        $this->RegisterVariableString('AlarmText', 'Alarmmeldung', '~HTMLBox', 110);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Objekte in Kategorien verschieben
        $this->SetObjectParent('GlobalActiveSwitch', 'SteuerungUndZeitplan');
        $this->SetObjectParent('WeeklyPlan', 'SteuerungUndZeitplan');
        $this->SetObjectParent('AlarmActive', 'Alarme');
        $this->SetObjectParent('AlarmText', 'Alarme');
    }
    
    public function StartCycle()
    {
        // ... (unveränderte Funktion)
    }
    
    public function SetAlarm(string $text) {
        // ... (unveränderte Funktion)
    }
    
    private function ResetAlarm() {
        // ... (unveränderte Funktion)
    }

    private function SetObjectParent($Ident, $ParentIdent) {
        $objID = @$this->GetIDForIdent($Ident);
        $parentID = @$this->GetIDForIdent($ParentIdent);
        if($objID && $parentID && (IPS_GetObject($objID)['ParentID'] != $parentID)) {
            IPS_SetParent($objID, $parentID);
        }
    }

    private function GetChildren() {
        // ... (unveränderte Funktion)
    }
}
