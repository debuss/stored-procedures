<?php

namespace Tests\Fixtures;

use StoredProcedures\Attribute\Column;

final class LegacyCountry
{

    #[Column('cntry_iso')]
    public string $iso = '';

    #[Column('cntry_name')]
    public string $name = '';
}
