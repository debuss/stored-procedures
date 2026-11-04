<?php

namespace StoredProcedures;

/**
 * State of a connection, shared with its copies (see {@see SqlConnection::withTimeout()}),
 * which use the same PDO instance.
 *
 * @internal
 */
final class ConnectionState
{

    public bool $lost = false;
}
