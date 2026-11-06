<?php

it('répond sur la route /up', function (): void {
    $this->get('/up')->assertOk();
});
