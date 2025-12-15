<?php
declare(strict_types=1);

namespace BlackCat\Database\Packages\WebauthnChallenges;

class ModuleException extends \RuntimeException {}
class RepositoryException extends ModuleException {}

class NotFoundException extends RepositoryException {}
class ConflictException extends RepositoryException {}          // duplicate key or similar constraint errors
class ConcurrencyException extends RepositoryException {}       // optimistic lock
class ValidationException extends ModuleException {}
class TransactionException extends ModuleException {}
