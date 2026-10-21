# A bounded, reversible release of the runner fixes on the unchanged official platform image.
ARG COOLIFY_BASE_IMAGE=coollabsio/coolify@sha256:df5d62a743dd0a786cb604a9550243ef5ca04f610056852aff8b69faa9a06698
FROM ${COOLIFY_BASE_IMAGE}
ARG PATCH_REVISION
LABEL io.worldsportsapi.runner-patch="LIA-379" \
      io.worldsportsapi.runner-patch-revision="${PATCH_REVISION}" \
      io.worldsportsapi.runner-patch-base="4.4.7"
COPY --chown=www-data:www-data app/Http/Controllers/Webhook/Github.php /var/www/html/app/Http/Controllers/Webhook/Github.php
COPY --chown=www-data:www-data app/Jobs/ProvisionGithubRunnerJob.php /var/www/html/app/Jobs/ProvisionGithubRunnerJob.php
COPY --chown=www-data:www-data app/Jobs/ReconcileGithubRunnersJob.php /var/www/html/app/Jobs/ReconcileGithubRunnersJob.php
COPY --chown=www-data:www-data app/Models/GithubRunnerExecution.php /var/www/html/app/Models/GithubRunnerExecution.php
COPY --chown=www-data:www-data app/Services/GithubRunner/GithubRunnerApi.php /var/www/html/app/Services/GithubRunner/GithubRunnerApi.php
COPY --chown=www-data:www-data app/Services/GithubRunner/GithubRunnerStatusSync.php /var/www/html/app/Services/GithubRunner/GithubRunnerStatusSync.php
COPY --chown=www-data:www-data app/Console/Commands/ReconcileGithubRunnerResults.php /var/www/html/app/Console/Commands/ReconcileGithubRunnerResults.php
RUN php -l app/Http/Controllers/Webhook/Github.php \
    && php -l app/Jobs/ProvisionGithubRunnerJob.php \
    && php -l app/Jobs/ReconcileGithubRunnersJob.php \
    && php -l app/Models/GithubRunnerExecution.php \
    && php -l app/Services/GithubRunner/GithubRunnerApi.php \
    && php -l app/Services/GithubRunner/GithubRunnerStatusSync.php \
    && php -l app/Console/Commands/ReconcileGithubRunnerResults.php
