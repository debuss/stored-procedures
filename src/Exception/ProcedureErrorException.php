<?php

namespace StoredProcedures\Exception;

/**
 * The stored procedure raised an error on purpose, usually a business rule violation:
 * SIGNAL SQLSTATE '45000' (MySQL), RAISE EXCEPTION (PostgreSQL), THROW or RAISERROR (SQL Server).
 */
class ProcedureErrorException extends ExecutionException
{

}
