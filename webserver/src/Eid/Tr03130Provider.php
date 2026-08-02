<?php

declare(strict_types=1);

namespace Stimmwerk\Eid;

/**
 * Anbindungspunkt für einen echten eID-Server nach BSI TR-03130.
 *
 * Erforderlich für den Echtbetrieb:
 *  - Berechtigungszertifikat (Vergabestelle für Berechtigungszertifikate im
 *    Bundesverwaltungsamt), Berechtigungsumfang: pseudonymer Zugang
 *    (Restricted Identification), keine Klardaten,
 *  - zugelassener eID-Server (eigenbetrieben oder als Dienst),
 *  - Aufruf des eID-Clients (AusweisApp) nach TR-03124 (tcTokenURL),
 *  - Prüfung von Echtheit und Sperrliste erfolgt im eID-Server.
 *
 * Diese Klasse ist bewusst ein harter Platzhalter: Sie schlägt fehl, statt
 * eine unvollständige Sicherheitsprüfung vorzutäuschen.
 */
final class Tr03130Provider implements EidProvider
{
    public function completeAuth(array $input): ?string
    {
        throw new \RuntimeException(
            'eID-Server-Anbindung (TR-03130) ist in dieser Installation nicht konfiguriert.'
        );
    }

    public function isMock(): bool
    {
        return false;
    }
}
