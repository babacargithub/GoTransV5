<?php

function is_request_for_gp_customers(): bool
{
    return request()->headers->has('source') && request()->headers->get('source') == 'gp';

}