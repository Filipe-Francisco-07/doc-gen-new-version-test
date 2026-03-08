<?php
use PHPUnit\Framework\TestCase;

/**
 * Classe de teste para validar asserções básicas utilizando PHPUnit.
 */
class ExampleTest extends TestCase
{
    /**
     * Verifica se a expressão fornecida é verdadeira.
     * 
     * @return void
     */
    public function testBasicAssertion(): void
    {
        $this->assertTrue(true);
    }
}
