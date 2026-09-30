<?php
class AppError extends RuntimeException { public function __construct(string $message,public int $status=422,public array $details=[]){parent::__construct($message);} }
