<?php

namespace App\Http\Controllers;

use App\Http\Requests\WordpressMigrationPluginPackageDownloadRequest;
use App\Http\Requests\WordpressMigrationResumeRequest;
use App\Http\Requests\WordpressMigrationStoreRequest;
use App\Jobs\MigrateWordpress;
use App\Models\WordpressMigration;
use App\System\Project\PhpHosting\WordPress\Migration\MigrationStarter;
use App\System\Project\PhpHosting\WordPress\Migration\MigratorPluginPackage;
use App\System\Project\PhpHosting\WordPress\Migration\SourceAccess;
use App\System\Project\PhpHosting\WordPress\Migration\SourceProbe;
use App\System\Project\PhpHosting\WordPress\Migration\WordpressMigratorZip;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use InvalidArgumentException;
use OpenApi\Attributes as OA;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Start, test, poll, resume, cancel and delete a WordPress site migration over REST and MCP,
 * and hand out the migrator plugin for the manual method. The
 * wordpress:migrate commands start through the same MigrationStarter and
 * queue the same job. None of them carries its own step list.
 */
class WordpressMigrationController extends Controller
{
    #[OA\Post(
        path: '/wordpress/migrations',
        summary: 'Start a WordPress site migration',
        description: 'Accepts the source site URL and how to reach it, stores the secret '
            .'(wp-admin password or plugin token) encrypted on the migration record, and queues '
            .'the migration job. method wp_admin (default) logs into the source wp-admin with '
            .'username and password and installs the migrator plugin there; login_url names a '
            .'custom login page. method manual_plugin takes no credentials: the user installs the '
            .'plugin from POST /wordpress/migrations/plugin-package and source_token is its token. '
            .'source_ip reaches the source at that public address with its own Host, e.g. while '
            .'its DNS already points elsewhere. Check the same body first with '
            .'POST /wordpress/migrations/test and pass the `domain` it answers: without `domain` a start '
            .'takes it from `url`, and once the job has found the site\'s real home it moves to that home\'s '
            .'domain (without www.) when it differs and is free, and fails when it is taken. '
            .'Returns 202 with the record id and status pending. The response never '
            .'includes the password or migrator token. The migration runs asynchronously '
            .'in a chain of bounded queue jobs and can take from minutes to several hours on a '
            .'large site. Poll the get endpoint every 1-2 minutes until status is completed, '
            .'failed or cancelled; do not start a second migration for the same '
            .'site while this one is running. The site keeps its own domain: a new empty classic '
            .'project is created on it with no tunnel (or the existing `project` is used), the '
            .'source sends the transfer to this engine\'s IPv4 whatever the domain\'s DNS says, '
            .'and with PanelAlpha Connect a preview tunnel shows the site until the DNS points here. '
            .'Then the engine issues the certificate and removes the preview by itself. Every call to the '
            .'source plugin\'s transfer routes carries the import\'s own token. A transfer already running on '
            .'the source that this engine did not start is never taken over: remote_sync fails naming it, '
            .'and a resume after it has finished goes on. An engine whose default IPv4 is not public, and not mapped '
            .'to a public address in the IPv4 NAT map, answers 409 '
            .'engine_address_private and stores nothing, since the source could not send the transfer to it; '
            .'EGRESS_ALLOW_PRIVATE lifts that for a source on the same private network.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['url'],
            properties: [
                new OA\Property(property: 'url', type: 'string', description: 'Source WordPress site URL. It, and every address it redirects to, must be public and not the Engine\'s own: loopback, link-local, private, reserved and Docker network addresses are refused, private ones unless the operator sets EGRESS_ALLOW_PRIVATE.', example: 'https://example.com/blog'),
                new OA\Property(property: 'method', type: 'string', enum: WordpressMigration::METHODS, default: 'wp_admin', description: 'wp_admin logs into the source wp-admin and installs the migrator plugin; manual_plugin uses the plugin the user installed from the plugin package.'),
                new OA\Property(property: 'login_url', type: 'string', nullable: true, description: 'wp_admin: the login page, when it is not <url>/wp-login.php (e.g. a custom login path).', example: 'https://example.com/secret-login'),
                new OA\Property(property: 'username', type: 'string', nullable: true, description: 'wp_admin, required: wp-admin username on the source site (not a hosting account name).', example: 'admin'),
                new OA\Property(property: 'password', type: 'string', nullable: true, description: 'wp_admin, required: wp-admin password. Never returned in any response; a started migration keeps it encrypted.', example: 'secret'),
                new OA\Property(property: 'source_token', type: 'string', nullable: true, description: 'manual_plugin, required: the token from the plugin package (64 hex characters). Never returned; a started migration keeps it encrypted.'),
                new OA\Property(property: 'source_ip', type: 'string', nullable: true, description: 'Reach the source at this public address with its own Host. Loopback, link-local, private, reserved and the Engine\'s own addresses are refused, private ones unless the operator sets EGRESS_ALLOW_PRIVATE.'),
                new OA\Property(
                    property: 'domain',
                    type: 'string',
                    nullable: true,
                    description: 'Domain the imported site gets. Defaults to the source host, lowercased, without '
                        .'a leading www. The site keeps its URLs when this equals the source host; otherwise '
                        .'the plugin rewrites them to this domain. 422 domain_taken when a project on this '
                        .'engine already has it; 422 domain_required for an IP source on an engine without '
                        .'PanelAlpha Connect.',
                    example: 'example.com'
                ),
                new OA\Property(
                    property: 'project',
                    type: 'string',
                    nullable: true,
                    description: 'Import into this existing classic project, on its own domain, instead of '
                        .'creating one. Its docroot (and the source subdirectory) must hold nothing but the '
                        .'placeholder index.html: 422 project_not_found, project_not_eligible, '
                        .'project_not_empty, or domain_mismatch when `domain` names another domain.',
                    example: 'shop'
                ),
                new OA\Property(
                    property: 'project_name',
                    type: 'string',
                    nullable: true,
                    description: 'Name of the project the import creates. Without it the name is derived from '
                        .'the domain and suffixed when taken; a taken project_name is 422 name_taken.',
                    example: 'examplecom'
                ),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'Migration accepted', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', example: 'pending'),
                    new OA\Property(property: 'source_url', type: 'string'),
                    new OA\Property(property: 'username', type: 'string', nullable: true),
                    new OA\Property(property: 'method', type: 'string', enum: WordpressMigration::METHODS),
                    new OA\Property(property: 'target_label', type: 'string', nullable: true),
                    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'step_log', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'target_project', type: 'string', nullable: true, description: 'The project the site is imported into; null until create_project names a new one.'),
                    new OA\Property(property: 'target_domain', type: 'string', nullable: true, description: 'The domain the imported site answers on; null until a panelalpha.online name is allocated for an IP source.'),
                    new OA\Property(property: 'created_project', type: 'boolean', description: 'Whether the import creates its project (false for an existing `project`).'),
                    new OA\Property(property: 'preview_url', type: 'string', nullable: true, description: 'panelalpha.online preview of the site while its domain still points elsewhere; null without Connect and once the domain points here.', example: 'https://wp-com.panelalpha.online'),
                    new OA\Property(property: 'dns', type: 'object', nullable: true, description: 'The A record to set so the domain reaches this engine; null for a panelalpha.online name.', properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                        new OA\Property(property: 'value', type: 'string', example: '203.0.113.7'),
                    ]),
                ],
            )),
            new OA\Response(response: 409, description: 'The source could not send the transfer to this engine: problems[].code is engine_address_private, and the message names the address', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error, or a refused target: problems[].code is domain_taken, domain_required, domain_mismatch, project_not_found, project_not_eligible, project_not_empty or name_taken', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function store(WordpressMigrationStoreRequest $request, MigrationStarter $starter): JsonResponse
    {
        $migration = $starter->start($request->validated(), $request->target());

        return new JsonResponse($migration->toStatusArray(), 202);
    }

    #[OA\Post(
        path: '/wordpress/migrations/test',
        summary: 'Test the connection to a WordPress site before migrating it',
        description: 'Takes the same body as starting a migration and runs its first step '
            .'synchronously: wp_admin finds the login page and logs in, manual_plugin finds the '
            .'migrator plugin and has it check source_token. Creates no record and changes nothing '
            .'on the source. Answers 200 either way: ok true with the site URL, the login URL, '
            .'the REST mode and the WordPress version, or ok false with error_type and message. '
            .'error_type is unreachable, tls (the certificate did not verify), not_wordpress, '
            .'cloudflare (a Cloudflare challenge blocks the login), login_failed (wrong wp-admin '
            .'credentials: offer the manual_plugin method), plugin_not_found, unauthorized '
            .'(the plugin refused source_token), engine_address_private (this engine\'s default IPv4 is not public '
            .'and not mapped to a public address, so '
            .'the source could not send the transfer to it), or one of the start\'s target refusals: domain_taken '
            .'(ask for another domain), domain_required (an IP source on an engine without PanelAlpha '
            .'Connect), domain_mismatch, project_not_found, project_not_eligible, project_not_empty or '
            .'name_taken. domain is the domain the import would get, from the site\'s own home '
            .'(lowercased, without www.) unless `domain` or `project` says otherwise; null when an IP '
            .'source gets a panelalpha.online name. Send it as `domain` when starting. The test '
            .'gives up after '.SourceProbe::DEADLINE_SECONDS.' seconds of waiting on the source (error_type unreachable), and at '
            .'most two tests run at once: one more answers 429, try it again in a moment.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(
            required: ['url'],
            properties: [
                new OA\Property(property: 'url', type: 'string', description: 'Source WordPress site URL. It, and every address it redirects to, must be public and not the Engine\'s own: loopback, link-local, private, reserved and Docker network addresses are refused, private ones unless the operator sets EGRESS_ALLOW_PRIVATE.', example: 'https://example.com/blog'),
                new OA\Property(property: 'method', type: 'string', enum: WordpressMigration::METHODS, default: 'wp_admin', description: 'wp_admin logs into the source wp-admin and installs the migrator plugin; manual_plugin uses the plugin the user installed from the plugin package.'),
                new OA\Property(property: 'login_url', type: 'string', nullable: true, description: 'wp_admin: the login page, when it is not <url>/wp-login.php (e.g. a custom login path).', example: 'https://example.com/secret-login'),
                new OA\Property(property: 'username', type: 'string', nullable: true, description: 'wp_admin, required: wp-admin username on the source site (not a hosting account name).', example: 'admin'),
                new OA\Property(property: 'password', type: 'string', nullable: true, description: 'wp_admin, required: wp-admin password. Never returned in any response; a started migration keeps it encrypted.', example: 'secret'),
                new OA\Property(property: 'source_token', type: 'string', nullable: true, description: 'manual_plugin, required: the token from the plugin package (64 hex characters). Never returned; a started migration keeps it encrypted.'),
                new OA\Property(property: 'source_ip', type: 'string', nullable: true, description: 'Reach the source at this public address with its own Host. Loopback, link-local, private, reserved and the Engine\'s own addresses are refused, private ones unless the operator sets EGRESS_ALLOW_PRIVATE.'),
                new OA\Property(property: 'domain', type: 'string', nullable: true, description: 'Domain the import would get; as on start.', example: 'example.com'),
                new OA\Property(property: 'project', type: 'string', nullable: true, description: 'Existing classic project to import into; as on start.'),
                new OA\Property(property: 'project_name', type: 'string', nullable: true, description: 'Name of the project the import would create; as on start.'),
            ],
        )),
        responses: [
            new OA\Response(response: 200, description: 'Test result', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'ok', type: 'boolean'),
                    new OA\Property(property: 'url', type: 'string', description: 'The site home as WordPress reports it. Only when ok.', example: 'https://example.com/blog'),
                    new OA\Property(property: 'login_url', type: 'string', nullable: true, description: 'The login page that worked. Only when ok.'),
                    new OA\Property(property: 'rest_mode', type: 'string', enum: ['wp_json', 'rest_route'], description: 'wp_json (/wp-json/) or rest_route (?rest_route=). Only when ok.'),
                    new OA\Property(property: 'wordpress_version', type: 'string', nullable: true, description: 'From the generator meta tag; null when the site hides it. Only when ok.'),
                    new OA\Property(property: 'domain', type: 'string', nullable: true, description: 'The domain the import would get; null when an IP source gets a panelalpha.online name. Only when ok.', example: 'example.com'),
                    new OA\Property(property: 'error_type', type: 'string', enum: ['unreachable', 'tls', 'not_wordpress', 'cloudflare', 'login_failed', 'plugin_not_found', 'unauthorized', 'engine_address_private', 'domain_taken', 'domain_required', 'domain_mismatch', 'project_not_found', 'project_not_eligible', 'project_not_empty', 'name_taken'], description: 'Only when not ok.'),
                    new OA\Property(property: 'message', type: 'string', description: 'Only when not ok.'),
                ],
            )),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
            new OA\Response(response: 429, description: 'Two tests are already running'),
        ],
    )]
    public function test(WordpressMigrationStoreRequest $request, SourceProbe $probe): JsonResponse
    {
        return new JsonResponse($probe->run(SourceAccess::fromValidated($request->validated()), $request->targetChoice()));
    }

    #[OA\Post(
        path: '/wordpress/migrations/plugin-package',
        summary: 'Get the migrator plugin for a manual_plugin migration',
        description: 'Mints a migration token and answers it with a signed download_url of the '
            .'wordpress-migrator plugin zip that carries it in its config.php. The user installs '
            .'that zip on the source site; start the migration with method manual_plugin and the '
            .'token as source_token. The link needs no bearer token and expires after 24 hours; '
            .'keep the token, the Engine does not store it.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        responses: [
            new OA\Response(response: 200, description: 'Plugin package', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'token', type: 'string', description: '64 hex characters.'),
                    new OA\Property(property: 'download_url', type: 'string'),
                    new OA\Property(property: 'expires_at', type: 'string', format: 'date-time'),
                ],
            )),
        ],
    )]
    public function pluginPackage(MigratorPluginPackage $package): JsonResponse
    {
        return new JsonResponse($package->mint());
    }

    /**
     * The signed link from {@see pluginPackage}: the release zip with the token in config.php.
     */
    public function downloadPluginPackage(WordpressMigrationPluginPackageDownloadRequest $request, WordpressMigratorZip $zip): BinaryFileResponse|JsonResponse
    {
        try {
            $package = $zip->packageWithToken($request->token());
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 502);
        }

        return (new BinaryFileResponse($package, 200, ['Content-Type' => 'application/zip']))
            ->setContentDisposition('attachment', 'wordpress-migrator.zip')
            ->deleteFileAfterSend();
    }

    #[OA\Get(
        path: '/wordpress/migrations/{id}',
        summary: 'Get WordPress migration status',
        description: 'Returns the migration steps and log. Never includes the password or migrator token. '
            .'Status is pending, running, completed, failed, cancelling or cancelled. stale is true '
            .'while a pending or running migration has not reported for 15 minutes: resume it. A step is only marked completed '
            .'when it finishes, so a long step such as migrate_files can stay pending for a long '
            .'time while the migration is still working: this is normal, keep polling. Treat the '
            .'migration as failed only when status is failed (the failing step and message are in '
            .'step_log). progress names the current step (step, step_index of step_count) and '
            .'gives percent over the whole run, weighted setup 0-10, database 10-40, files 40-95 '
            .'and finish 95-100; phase_percent is how far the current phase is. While the '
            .'database or files transfer, item is the table or file being sent and message counts '
            .'the chunks. updated_at is the run\'s heartbeat: a stale one while status is running '
            .'means the run has stalled. warnings lists what the import noticed and went on with: '
            .'plugin_unauthenticated means the source plugin is older than 1.2.0 and answers its transfer '
            .'routes without a token, so anyone can copy the site while it is installed; the engine removes it '
            .'(wp_admin), or the user must (manual_plugin, see notices), as soon as the import ends, fails or '
            .'is cancelled.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Migration status', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'running', 'completed', 'failed', 'cancelling', 'cancelled']),
                    new OA\Property(property: 'stale', type: 'boolean', description: 'Pending or running without a heartbeat for 15 minutes; resume is allowed.'),
                    new OA\Property(property: 'source_url', type: 'string', description: 'The site home once the source has been found.'),
                    new OA\Property(property: 'username', type: 'string', nullable: true),
                    new OA\Property(property: 'method', type: 'string', enum: WordpressMigration::METHODS),
                    new OA\Property(property: 'login_url', type: 'string', nullable: true),
                    new OA\Property(property: 'rest_mode', type: 'string', nullable: true, enum: ['wp_json', 'rest_route']),
                    new OA\Property(property: 'source_ip', type: 'string', nullable: true),
                    new OA\Property(property: 'notices', type: 'array', items: new OA\Items(type: 'string'), description: 'What the user still has to do by hand, e.g. remove the plugin from a manual_plugin source.'),
                    new OA\Property(property: 'warnings', type: 'array', description: 'What the import noticed and went on with.', items: new OA\Items(type: 'object', properties: [
                        new OA\Property(property: 'code', type: 'string', enum: ['plugin_unauthenticated']),
                        new OA\Property(property: 'message', type: 'string'),
                    ])),
                    new OA\Property(property: 'target_label', type: 'string', nullable: true),
                    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'step_log', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'target_project', type: 'string', nullable: true, description: 'The project the site is imported into; null until create_project names a new one.'),
                    new OA\Property(property: 'target_domain', type: 'string', nullable: true, description: 'The domain the imported site answers on; null until a panelalpha.online name is allocated for an IP source.'),
                    new OA\Property(property: 'created_project', type: 'boolean', description: 'Whether the import creates its project (false for an existing `project`).'),
                    new OA\Property(property: 'preview_url', type: 'string', nullable: true, description: 'panelalpha.online preview of the site while its domain still points elsewhere; null without Connect and once the domain points here.', example: 'https://wp-com.panelalpha.online'),
                    new OA\Property(property: 'dns', type: 'object', nullable: true, description: 'The A record to set so the domain reaches this engine; null for a panelalpha.online name.', properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                        new OA\Property(property: 'value', type: 'string', example: '203.0.113.7'),
                    ]),
                    new OA\Property(property: 'progress', type: 'object', properties: [
                        new OA\Property(property: 'step', type: 'string', nullable: true, example: 'migrate_files'),
                        new OA\Property(property: 'step_index', type: 'integer', description: '1-based position of step in steps.', example: 8),
                        new OA\Property(property: 'step_count', type: 'integer', example: 11),
                        new OA\Property(property: 'percent', type: 'number', format: 'float', description: 'The whole run, 0-100.', example: 74.9),
                        new OA\Property(property: 'phase_percent', type: 'number', format: 'float', description: 'The current phase (setup, database, files or finish), 0-100.', example: 63.4),
                        new OA\Property(property: 'item', type: 'string', nullable: true, description: 'The table or file being sent.', example: 'wp-content/uploads/2024/05/a.jpg'),
                        new OA\Property(property: 'message', type: 'string', nullable: true, example: 'Copying the files: 634/1000 chunks of 2210 files'),
                        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', nullable: true, description: 'Heartbeat of the run; null before it starts.'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse(self::findOr404($id)->toStatusArray());
    }

    #[OA\Post(
        path: '/wordpress/migrations/{id}/resume',
        summary: 'Resume a failed or stalled WordPress migration',
        description: 'Re-queues a migration that is in status failed, or that is running or pending '
            .'but stale (no heartbeat in progress.updated_at for 15 minutes, e.g. its worker died), '
            .'and continues from the step that did not complete. Steps are safe to repeat: the '
            .'project the import created is adopted, WordPress core and its database are not '
            .'created twice. A wp_admin migration that failed after its plugin reached the source '
            .'has had the plugin removed there; resume installs it again and restarts the '
            .'transfer. Rejects any other status with 409, including a running migration that is '
            .'still reporting: wait for it. A migration that has had no activity for 7 days loses its '
            .'stored password and tokens (step_log says secrets_expired): resume it with password '
            .'(wp_admin) or source_token (manual_plugin) again, otherwise 422. A transfer it left '
            .'unfinished then starts again from install_destination_plugin.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(required: false, content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'password', type: 'string', nullable: true, description: 'wp_admin: the wp-admin password, required once it was cleared; replaces the stored one. Never returned.'),
                new OA\Property(property: 'source_token', type: 'string', nullable: true, description: 'manual_plugin: the plugin token (64 hex characters), required once it was cleared; replaces the stored one. Never returned.'),
            ],
        )),
        responses: [
            new OA\Response(response: 202, description: 'Resume accepted', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', example: 'pending'),
                    new OA\Property(property: 'source_url', type: 'string'),
                    new OA\Property(property: 'username', type: 'string'),
                    new OA\Property(property: 'target_label', type: 'string', nullable: true),
                    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'step_log', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'target_project', type: 'string', nullable: true, description: 'The project the site is imported into; null until create_project names a new one.'),
                    new OA\Property(property: 'target_domain', type: 'string', nullable: true, description: 'The domain the imported site answers on; null until a panelalpha.online name is allocated for an IP source.'),
                    new OA\Property(property: 'created_project', type: 'boolean', description: 'Whether the import creates its project (false for an existing `project`).'),
                    new OA\Property(property: 'preview_url', type: 'string', nullable: true, description: 'panelalpha.online preview of the site while its domain still points elsewhere; null without Connect and once the domain points here.', example: 'https://wp-com.panelalpha.online'),
                    new OA\Property(property: 'dns', type: 'object', nullable: true, description: 'The A record to set so the domain reaches this engine; null for a panelalpha.online name.', properties: [
                        new OA\Property(property: 'type', type: 'string', example: 'A'),
                        new OA\Property(property: 'value', type: 'string', example: '203.0.113.7'),
                    ]),
                ],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Migration is neither failed nor stale', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
            new OA\Response(response: 422, description: 'The cleared password or source_token was not sent', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorResponse')),
        ],
    )]
    public function resume(WordpressMigrationResumeRequest $request, int $id): JsonResponse
    {
        $migration = self::findOr404($id);
        self::or409(static fn () => $migration->resume($request->sourceSecret()));
        MigrateWordpress::dispatch($migration->id);

        return new JsonResponse($migration->fresh()->toStatusArray(), 202);
    }

    #[OA\Post(
        path: '/wordpress/migrations/{id}/cancel',
        summary: 'Cancel a WordPress migration and remove what it made',
        description: 'Sets status cancelling and queues the cancel; a running migration stops between '
            .'steps and batches. The cancel stops the transfer on the source (manual_plugin), removes '
            .'the migrator plugin from the source (wp_admin only: a manual_plugin source keeps the '
            .'plugin the user installed), deletes the project the import created, or removes the '
            .'imported files and database from an existing `project`, removes the preview tunnel and '
            .'clears the stored password and tokens. It ends as cancelled. What could not be done on the '
            .'source is noted in step_log; anything left on this engine keeps the migration cancelling, '
            .'and cancelling it again retries. Allowed for pending, running, failed and cancelling '
            .'migrations; 409 for completed or cancelled ones.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 202, description: 'Cancel accepted', content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'id', type: 'integer'),
                    new OA\Property(property: 'status', type: 'string', example: 'cancelling'),
                    new OA\Property(property: 'steps', type: 'array', items: new OA\Items(type: 'object')),
                    new OA\Property(property: 'step_log', type: 'array', items: new OA\Items(type: 'object')),
                ],
            )),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Migration is completed or cancelled', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function cancel(int $id): JsonResponse
    {
        $migration = self::findOr404($id);
        self::or409(static fn () => $migration->requestCancel());
        MigrateWordpress::dispatch($migration->id);

        return new JsonResponse($migration->fresh()->toStatusArray(), 202);
    }

    #[OA\Delete(
        path: '/wordpress/migrations/{id}',
        summary: 'Delete a finished WordPress migration record',
        description: 'Removes the record of a completed, failed or cancelled migration. Only the record '
            .'goes: a completed import\'s site stays, and a failed one\'s project and plugins stay too, '
            .'so cancel a failed migration first to remove them. 409 while it is pending, running or cancelling.',
        security: [['bearerAuth' => []]],
        tags: ['WordPress Migrations'],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Deleted'),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 409, description: 'Migration has not ended', content: new OA\JsonContent(
                properties: [new OA\Property(property: 'message', type: 'string')],
            )),
        ],
    )]
    public function destroy(int $id): Response
    {
        $migration = self::findOr404($id);
        self::or409(static fn () => $migration->deleteEnded());

        return response()->noContent();
    }

    private static function findOr404(int $id): WordpressMigration
    {
        $migration = WordpressMigration::query()->find($id);
        if ($migration === null) {
            abort(new JsonResponse(['message' => 'Not found'], 404));
        }

        return $migration;
    }

    /**
     * A transition the record's status refuses answers 409.
     */
    private static function or409(callable $transition): void
    {
        try {
            $transition();
        } catch (InvalidArgumentException $e) {
            abort(new JsonResponse(['message' => $e->getMessage()], 409));
        }
    }
}
