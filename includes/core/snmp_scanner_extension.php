<?php

namespace Portflow\Core;

interface SnmpScannerExtensionInterface
{
    public function getId(): string;

    /**
     * @param array<string,mixed> $config
     * @param array<string,mixed> $switch
     */
    public function supports(array $config, array $switch): bool;

    /**
     * @param array<string,mixed> $config
     * @return array{map:array<int|string,string>,source:string}|null
     */
    public function collectVlanNames(SnmpClient $client, Logger $logger, array $config): ?array;

    /**
     * @param array<string,mixed> $config
    * @return array{admin:array<string,int>,detection:array<string,int|null>,class:array<string,int|null>,source:string,consumption?:array<string,int>,port_name?:array<string,string>,main_consumption?:array<int,int>}|null
     */
    public function collectPoeSnapshot(SnmpClient $client, Logger $logger, array $config): ?array;

    /**
     * @param array<string,mixed> $config
        * @return array<string,array{if_index:int,ip:string,hostname:string,source:string,mac:string,if_name?:string,vlan?:int|null}>
     */
    public function collectNodeIps(SnmpClient $client, Logger $logger, array $config): array;

    /**
     * @return array<string,mixed>
     */
    public function getLastDiagnostics(): array;
}