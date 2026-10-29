<?php

namespace App\OpenApi\Schemas;

use OpenApi\Attributes as OA;

#[OA\Schema(
    schema: 'SslCheckName',
    properties: [
        new OA\Property(property: 'common_name', type: 'string', nullable: true, example: 'R11'),
        new OA\Property(property: 'organization', type: 'string', nullable: true, example: "Let's Encrypt"),
        new OA\Property(property: 'country', type: 'string', nullable: true, example: 'US'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SslCheckCertificate',
    properties: [
        new OA\Property(property: 'status', type: 'string', enum: ['trusted', 'self_signed', 'untrusted_issuer', 'expired', 'not_yet_valid', 'domain_mismatch', 'unreadable']),
        new OA\Property(property: 'issuer', type: 'string', example: "Let's Encrypt"),
        new OA\Property(property: 'common_name', type: 'string', example: 'shop.example.com'),
        new OA\Property(property: 'names', type: 'array', items: new OA\Items(type: 'string')),
        new OA\Property(property: 'self_signed', type: 'boolean'),
        new OA\Property(property: 'chain_trusted', type: 'boolean', nullable: true),
        new OA\Property(property: 'covers_domain', type: 'boolean'),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'days_remaining', type: 'integer', nullable: true),
        new OA\Property(property: 'signer', ref: '#/components/schemas/SslCheckName', nullable: true),
        new OA\Property(property: 'serial_number', type: 'string', nullable: true),
        new OA\Property(property: 'signature_algorithm', type: 'string', nullable: true, example: 'sha256WithRSAEncryption'),
        new OA\Property(property: 'key', properties: [
            new OA\Property(property: 'type', type: 'string', nullable: true, example: 'EC'),
            new OA\Property(property: 'bits', type: 'integer', nullable: true, example: 256),
            new OA\Property(property: 'curve', type: 'string', nullable: true, example: 'prime256v1'),
        ], type: 'object', nullable: true),
        new OA\Property(property: 'fingerprint_sha256', type: 'string', nullable: true),
        new OA\Property(property: 'chain', description: 'The certificates the server sent after this one, in order', type: 'array', items: new OA\Items(properties: [
            new OA\Property(property: 'subject', ref: '#/components/schemas/SslCheckName', nullable: true),
            new OA\Property(property: 'signer', ref: '#/components/schemas/SslCheckName', nullable: true),
            new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        ], type: 'object')),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SslCheckProbe',
    properties: [
        new OA\Property(property: 'address', type: 'string', example: '203.0.113.45'),
        new OA\Property(property: 'answered', type: 'boolean'),
        new OA\Property(property: 'refused', type: 'boolean'),
        new OA\Property(property: 'error', type: 'string', nullable: true),
        new OA\Property(property: 'certificate', ref: '#/components/schemas/SslCheckCertificate', nullable: true),
        new OA\Property(property: 'serves_installed', type: 'boolean', nullable: true, description: 'engine only'),
    ],
    type: 'object',
)]
#[OA\Schema(
    schema: 'SslCheck',
    properties: [
        new OA\Property(property: 'domain', type: 'string', example: 'shop.example.com'),
        new OA\Property(property: 'status', type: 'string', enum: ['trusted', 'self_signed', 'untrusted_issuer', 'expired', 'not_yet_valid', 'domain_mismatch', 'unreadable', 'not_resolving', 'refused_address', 'unreachable']),
        new OA\Property(property: 'checked_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'issuer', description: 'Of the certificate visitors get; null when there was none', type: 'string', nullable: true),
        new OA\Property(property: 'signer', ref: '#/components/schemas/SslCheckName', nullable: true),
        new OA\Property(property: 'valid_from', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'expires_at', type: 'string', format: 'date-time', nullable: true),
        new OA\Property(property: 'days_remaining', type: 'integer', nullable: true),
        new OA\Property(property: 'dns', properties: [
            new OA\Property(property: 'addresses', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'expected', type: 'array', items: new OA\Items(type: 'string')),
            new OA\Property(property: 'points_here', type: 'boolean'),
        ], type: 'object'),
        new OA\Property(property: 'public', ref: '#/components/schemas/SslCheckProbe', nullable: true),
        new OA\Property(property: 'engine', ref: '#/components/schemas/SslCheckProbe', nullable: true),
    ],
    type: 'object',
)]
class SslCheckSchema {}
