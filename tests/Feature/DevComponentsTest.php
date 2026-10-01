<?php

declare(strict_types=1);

it('only serves the component gallery in local development', function (): void {
    // phpunit.xml runs with APP_ENV=testing, like every non-local environment.
    $this->get('/dev/components')->assertNotFound();
});
