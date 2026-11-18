# Nachtrag: MeterHub in der Praxis - eine Anlage mit 12 Trafo-Gruppen

Ein kurzer Nachtrag, um zu zeigen, dass das Ganze auch bei größerem Umfang trägt - nicht nur am
heimischen Balkonkraftwerk.

## Die Dashboard-Kachel

Eine reale kommerzielle PV-Park-Anlage mit **12 Trafo-Gruppen** und insgesamt weit über 400
Wechselrichtern (Meteocontrol blue'Log SCADA, siehe oben). Die Energiefluss-Kachel des
Geschwistermoduls **NRG-Stack Dashboard** zeigt alle 12 Gruppen auf einen Blick, inklusive
Netzeinspeisung und Saldo in der Mitte:

*(Screenshot 1: Dashboard-Kachel, radiale Übersicht aller 12 Trafo-Gruppen)*

Klick auf eine Gruppe klappt sie auf und zeigt jeden einzelnen Wechselrichter - hier „Trafo 4.1"
mit allen 24 Mitgliedern und deren Momentanleistung:

*(Screenshot 2: aufgeklappte Ansicht „Trafo 4.1", 24 Wechselrichter einzeln)*

## Die Verdrahtung dahinter

Jeder WR eine eigene MeterHub-Instanz, jede Trafo-Gruppe eine `MeterHubVirtual`-Instanz, die ihre
WR über den Objektbaum als Mitglieder zusammenfasst - kein manuelles Verdrahten pro
Wechselrichter, die Formel entsteht allein aus der Anordnung im Baum:

*(Screenshot 3: Objektbaum - „Trafo 1", „Trafo 1.1" mit WR 1.1.01.01 … 1.1.04.06 als Mitglieder,
je WR mit Summenwerten/Energiezähler/Verbindungsstatus)*

Jede Trafo-Instanz liefert am Ende genau zwei Zahlen: **Leistung** und **Einspeisung** - egal ob
dahinter 6 oder 24 Wechselrichter hängen.

## Null manuelle Verdrahtung auf der Dashboard-Seite

Der eigentliche Punkt: Das Dashboard-Modul findet diese 12 Gruppen komplett **von selbst** - über
denselben `MHUBV_GetFunctions()`-Vertrag, den auch InverterHub und WPHub nutzen. Kein einziges
Gerät wurde hier von Hand in der Kachel eingetragen:

*(Screenshot 4: Dashboard-Konfiguration, „13 Geräte gefunden", automatisch mit Rolle „pv" und
Quelle „metehub" gelistet)*

Das ist der Kern der ganzen Vertrags-Idee im NRG-Stack: MeterHub/MeterHubVirtual liefern nur die
Zahlen und ihre Funktion (`pv`, `grid`, `heatpump`, …) - was ein Konsument daraus macht (Kachel,
Sankey, Auswertung), ist dessen Sache. Neue Trafo-Gruppe hinzufügen heißt: Instanz anlegen, im
Objektbaum einsortieren, fertig - die Kachel zieht automatisch nach.
