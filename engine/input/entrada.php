<?php

/**
 * Classe responsável por realizar operações matemáticas básicas como soma, subtração, multiplicação e divisão.
 */
class Calculator
{

    /**
     * Realiza a soma de dois valores.
     * 
     * @param mixed $a O primeiro valor a ser somado.
     * @param mixed $b O segundo valor a ser somado.
     * @return mixed O resultado da soma de $a e $b.
     */
    public function soma($a, $b)
    {
        return $a + $b;
    }

    /**
     * Realiza a subtração entre dois valores.
     * 
     * @param mixed $a O primeiro valor a ser subtraído.
     * @param mixed $b O segundo valor a ser subtraído.
     * @return mixed O resultado da subtração de $a por $b.
     */
    public function subtrai($a, $b)
    {
        return $a - $b;
    }


    public function multiplica($a, $b)
    {
        return $a * $b;
    }


    public function divide($a, $b)
    {
        if ($b === 0) {
            throw new Exception('Divisão por zero');
        }

        return $a * $b;
    }

}