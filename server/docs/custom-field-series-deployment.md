# Custom Field series backend deployment preparation

## Identified target and workflow

The frontend's `.env` and `.env.development.local` both target
`https://api-jucans.codebuilders.ro/api`. Treat this as production: the user
identified it as live and the local PhpStorm deployment profile is named
`[LIVE]codebuilders`. No remote APP_ENV or remote configuration was inspected.

Existing local IDE configuration (ignored by Git):
- `server/.idea/deployment.xml`: server root maps to deployment `/`.
- `server/.idea/webServers.xml`: FTP host `codebuilders.ro`, port 21,
  remote root `/api-jucans.codebuilders.ro`.

No CI deployment pipeline, container definition, hosting provisioning recipe,
or production shell/release script was found in this repository. Use the
existing PhpStorm **Deployment → Upload to [LIVE]codebuilders** workflow only
after explicit production authorization. Do not invent an SSH user, shell
path, PHP binary, hosting panel, or service-restart command.

## HTTP PHP prerequisite — still unconfirmed

The remote HTTP PHP version, SAPI (FPM/Apache/CGI/etc.), active ini files and
BCMath availability cannot be inferred from the local CLI, IDE language level
or Laravel's `.htaccess`. No remote access/probe was performed in this task.
The hosting administrator must identify the PHP handler/version assigned to
this exact domain through its existing hosting configuration, then confirm
BCMath is enabled for that handler. Confirm the matching CLI separately if
Composer runs on the host. Do not publish a public phpinfo/diagnostic endpoint.
Do not install extensions or change hosting settings without authorization.

The implementation requires PHP >=8.1 as already declared, existing Phase 1/2
code/schema, and BCMath. `composer.json` and `composer.lock` now declare
`ext-bcmath: *`; package versions are unchanged. Normal Composer install must
not use `--ignore-platform-reqs` or ignore `ext-bcmath`.

Composer checks its own runtime; the default generated runtime platform check
is PHP-only, so Composer success does not establish HTTP extension availability.
The HTTP handler must be confirmed separately before publishing. The endpoint
returns controlled HTTP 503 JSON before any BCMath calls:

```json
{"code":"numeric_series_unavailable","message":"Numeric charts are unavailable. Enable PHP BCMath in the HTTP runtime and reload PHP.","errors":{"runtime":["PHP BCMath is required for exact numeric aggregation."]}}
```

There is no floating-point fallback.

## Exact feature payload (paths relative to server/)

- `app/Http/Controllers/Api/ProjectCustomFieldSeriesController.php`
- `app/Http/Requests/Project/ProjectCustomFieldSeriesRequest.php`
- `app/Services/ProjectCustomFieldSeriesService.php`
- `routes/api.php`
- `composer.json`
- `composer.lock`

Documentation: `docs/project-custom-field-series.md` and this document.
Do not upload `.env`, `.idea`, tests, local credentials, databases or storage.
Do not deploy the entire dirty working tree automatically. The route file
contains existing Phase 1/2 route additions too: reconcile it against the
current release before upload. The six-file payload assumes all Phase 1/2
controllers, models, middleware and services are already present remotely.
If they are not, prepare that prerequisite release separately; do not publish
a route file referring to absent classes.

## Authorized release steps — not executed

1. Identify the domain's real HTTP PHP handler and confirm BCMath there.
   Confirm the existing Phase 1/2 application code/schema remotely through
   existing deployment records/read-only inspection. No chart migration exists.
2. Obtain explicit authorization to deploy production. Use the existing FTP
   profile for the six-file payload with a release backup and the site's
   established write-pause/release procedure. FTP is not atomic; do not expose
   a route referencing a class before that class is uploaded.
3. In the deployed Laravel root, with the host's approved matching PHP/Composer
   executable, run the following dependency commands. These are standard
   Laravel/Composer preparation commands, not a discovered remote shell script:

   ```sh
   composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
   composer check-platform-reqs --no-dev
   ```

   If shell access/Composer is unavailable, the deployment owner must use its
   existing build-and-vendor publication process. Do not improvise a remote
   package installation or upload a locally generated vendor tree across
   different PHP platforms. Include the generated autoload/platform files in
   that established artifact process.
4. Refresh caches only according to the actual hosting release procedure:
   if routes are cached, run `php artisan route:cache` using the matching PHP;
   if configuration is cached and the release process rebuilds it, run
   `php artisan config:cache`. Reload OPcache/PHP through the hosting's existing
   mechanism if required. Those mechanisms and executable paths remain unknown.
   Do not run `artisan migrate`, reset, seed, or any Phase 1/2 down migration.

No deployment, remote commands, remote data changes, tests, browser verification
or manual application scenarios were performed. The frontend API URL remains
unchanged. The next action is for the deployment owner to confirm the live
HTTP PHP handler/BCMath and prerequisite release; production publication then
requires explicit authorization.
