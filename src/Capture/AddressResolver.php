<?php

declare(strict_types=1);

namespace SeoLab\Capture;

/**
 * Seam di risoluzione DNS per l'origine. Restituisce tutti gli indirizzi (IPv4 e IPv6) noti per l'host, lista vuota se nessuno.
 */
interface AddressResolver
{
    /**
     * @return string[]
     */
    public function resolve(string $host): array;
}
