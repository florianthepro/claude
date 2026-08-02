<?php

declare(strict_types=1);

namespace Stimmwerk\Eid;

/**
 * Abstraktion der Ausweis-Identitätsprüfung.
 *
 * Produktion: Authentisierung über einen zugelassenen eID-Server
 * (BSI TR-03130) mit dem eID-Client des Nutzers (AusweisApp, TR-03124).
 * Der Chip des Personalausweises weist seine Echtheit kryptographisch nach
 * (private Schlüssel im Chip, Prüfung gegen die staatliche Zertifikatskette);
 * die Plattform erhält ausschließlich das dienstespezifische Pseudonym
 * (Restricted Identification) – keine Klardaten.
 */
interface EidProvider
{
    /**
     * Schließt eine Authentisierung ab und liefert das kartenstabile
     * Pseudonym – oder null, wenn die Prüfung fehlschlägt.
     *
     * @param array<string,mixed> $input Provider-spezifische Eingaben
     */
    public function completeAuth(array $input): ?string;

    /** true, wenn dieser Provider nur eine Simulation ist. */
    public function isMock(): bool;
}
