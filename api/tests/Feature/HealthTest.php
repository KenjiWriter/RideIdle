<?php

declare(strict_types=1);

it('responds to the health check', function () {
    $this->get('/up')->assertOk();
});
