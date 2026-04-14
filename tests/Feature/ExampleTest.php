<?php

it('redirects root to locale-prefixed home', function () {
    $response = $this->get('/');

    $response->assertRedirect();
});
