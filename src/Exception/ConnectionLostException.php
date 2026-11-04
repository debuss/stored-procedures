<?php

namespace StoredProcedures\Exception;

/**
 * The connection to the database server was lost: closed by the server (idle timeout, restart, failover...)
 * or by the network.
 *
 * The connection, and its PDO instance, must be replaced. Whether the call was executed is unknown,
 * and a transaction in progress was rolled back by the server.
 */
class ConnectionLostException extends ExecutionException
{

}
