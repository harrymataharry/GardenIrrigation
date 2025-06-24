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
        
        // Kategorien mit Standard-Funktionen erstellen
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
        
        // Variablen erstellen
        $this->RegisterVariableBoolean('GlobalActiveSwitch', 'Bewässerung aktiv', '~Switch', 10);
        $this->EnableAction('GlobalActiveSwitch');
        
        if (!IPS_VariableProfileExists('BEW.Rainfall.lpm2')) {
            IPS_CreateVariableProfile('BEW.Rainfall.lpm2', 2); // 2 = Float
            IPS_SetVariableProfileText('BEW.Rainfall.lpm2', '', ' l/m²');
            IPS_SetVariableProfileDigits('BEW.Rainfall.lpm2', 2);
        }
        $this->RegisterVariableFloat('RainfallLastPeriod', 'Regenmenge im Prüfzeitraum', 'BEW.Rainfall.lpm2', 20);

        // --- KORREKTUR: WOCHENPLAN MIT STANDARD-FUNKTIONEN ERSTELLEN ---
        if (@IPS_GetObjectIDByIdent('WeeklyPlan', $this->InstanceID) === false) {
            $eventID = IPS_CreateEvent(2); // Typ 2 = Wochenplan
            IPS_SetName($eventID, 'Bewässerungsplan');
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetIdent($eventID, 'WeeklyPlan');
        } else {
            $eventID = IPS_GetObjectIDByIdent('WeeklyPlan', $this->InstanceID);
        }
        IPS_SetEventScheduleAction($eventID, 0, 'Bewässerung starten', 0x0000FF, 'BEW_StartCycle(' . $this->InstanceID . ');');


        // Alarm-Variablen erstellen
        $this->RegisterVariableBoolean('AlarmActive', 'Alarm aktiv', '~Alert', 100);
        $this->RegisterVariableString('AlarmText', 'Alarmmeldung', '~HTMLBox', 110);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Objekte in Kategorien verschieben
        $this->SetObjectParent('GlobalActiveSwitch', 'SteuerungUndZeitplan');
        // Den Wochenplan können wir nicht direkt über SetObjectParent verschieben, da er keine Standard-Variable ist.
        // Die Parent-Zuweisung in Create() ist ausreichend.
        $this->SetObjectParent('AlarmActive', 'Alarme');
        $this->SetObjectParent('AlarmText', 'Alarme');
    }
    
    // ... der Rest der Datei (StartCycle, SetAlarm etc.) bleibt unverändert ...
    
    public function StartCycle()
    {
        $this->SendDebug('StartCycle', 'Manueller oder geplanter Start des Bewässerungszyklus', 0);

        $globalActiveID = $this->GetIDForIdent('GlobalActiveSwitch');
        if (GetValue($globalActiveID) == false) {
            $this->SendDebug('StartCycle', 'Bewässerung ist über den internen Schalter deaktiviert. Abbruch.', 0);
            return;
        }

        $powerEnergyID = $this->ReadPropertyInteger('PowerEnergyID');
        if ($powerEnergyID > 0) {
            $lastUpdate = IPS_GetVariable($powerEnergyID)['VariableChanged'];
            if (time() - $lastUpdate > 900) {
                $this->SetAlarm('Stromversorgung scheint inaktiv (keine Zähler-Aktualisierung).');
                return;
            }
        }
        $powerStateID = $this->ReadPropertyInteger('PowerStateID');
        if ($powerStateID > 0 && !GetValue($powerStateID)) {
            $this->SetAlarm('Stromversorgung ist laut Status-Variable ausgeschaltet.');
            return;
        }
        $this->SendDebug('StartCycle', 'Stromversorgung OK.', 0);

        $archiveID = $this->ReadPropertyInteger('ArchiveID');
        $rainVarID = $this->ReadPropertyInteger('RainVariableID');
        $lookbackHours = $this->ReadPropertyInteger('LookbackHours');
        if ($archiveID > 0 && $rainVarID > 0 && $lookbackHours > 0) {
            $now = time();
            $startTime = $now - ($lookbackHours * 3600);
            $loggedValues = AC_GetLoggedValues($archiveID, $rainVarID, $startTime, $now, 0);

            $maxRainValue = 0;
            if (is_array($loggedValues) && count($loggedValues) > 0) {
                foreach ($loggedValues as $record) {
                    if ($record['Value'] > $maxRainValue) {
                        $maxRainValue = $record['Value'];
                    }
                }
            }
            
            $rainLastXHours = round($maxRainValue, 2);
            SetValue($this->GetIDForIdent('RainfallLastPeriod'), $rainLastXHours);
            
            $rainThreshold = $this->ReadPropertyInteger('RainThreshold');
            if ($rainLastXHours >= $rainThreshold) {
                $this->SendDebug('StartCycle', "Genug Regen in den letzten $lookbackHours Stunden. Bewässerung wird übersprungen.", 0);
                return;
            }
        }

        $this->SendDebug('StartCycle', 'Alle globalen Prüfungen bestanden. Starte Bewässerung der Flächen.', 0);
        $this->ResetAlarm();

        $childrenIDs = $this->GetChildren();
        foreach ($childrenIDs as $childID) {
            $this->SendDebug('StartCycle', "Starte Bewässerung für Fläche $childID.", 0);
            try {
                BEWArea_StartWatering($childID);
            } catch (Exception $e) {
                $this->SendDebug('StartCycle', "Fehler beim Starten der Fläche $childID: " . $e->getMessage(), 0);
            }
            IPS_Sleep(5000);
        }
    }
    
    public function SetAlarm(string $text) {
        $this->SendDebug('SetAlarm', "Alarm ausgelöst: $text", 0);
        IPS_LogMessage('Bewässerung Alarm', $text);
        SetValue($this->GetIDForIdent('AlarmActive'), true);
        SetValue($this->GetIDForIdent('AlarmText'), $text);
    }
    
    private function ResetAlarm() {
        SetValue($this->GetIDForIdent('AlarmActive'), false);
        SetValue($this->GetIDForIdent('AlarmText'), "");
    }

    private function SetObjectParent($Ident, $ParentIdent) {
        $objID = @$this->GetIDForIdent($Ident);
        $parentID = @$this->GetIDForIdent($ParentIdent);
        if($objID && $parentID && (IPS_GetObject($objID)['ParentID'] != $parentID)) {
            IPS_SetParent($objID, $parentID);
        }
    }

    private function GetChildren() {
        $childIDs = IPS_GetChildrenIDs($this->InstanceID);
        $areaIDs = [];
        foreach($childIDs as $id) {
            if(IPS_GetInstance($id)['ModuleInfo']['ModuleID'] == '{C2D4E5F6-A7B8-4C9D-0E1F-2A3B4C5D6E7F}') {
                $areaIDs[] = $id;
            }
        }
        return $areaIDs;
    }
}
