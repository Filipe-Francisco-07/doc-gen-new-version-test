<?php

namespace Analyser;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter\Standard as PrettyPrinter;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\While_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Throw_;
use PhpParser\Node\Stmt\Echo_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\PreInc;
use PhpParser\Node\Expr\PostInc;
use PhpParser\Node\Expr\PreDec;
use PhpParser\Node\Expr\PostDec;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\Div as OpDiv;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\Isset_;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Print_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;

/**
 * Classe responsável por analisar e marcar a documentação de nós no código, coletando informações sobre classes, métodos, funções e suas interações.
 */
final class MarcadorDocumentacao extends NodeVisitorAbstract
{
    /**
     * @var array Lista de itens.
     */
    public array $aItens = [];

    /**
     * @var int Identificador do próximo item.
     */
    private int $iProxId = 1;
    /**
     * @var array Array que representa a pilha de classes.
     */
    private array $aPilhaClasse = [];

    /**
     * @var NodeFinder $oFind Instance of NodeFinder for finding nodes.
     */
    private NodeFinder $oFind;
    /**
     * @var PrettyPrinter Instance of the PrettyPrinter class.
     */
    private PrettyPrinter $oPP;

    /**
     * Inicializa as propriedades oFind e oPP com novas instâncias de NodeFinder e PrettyPrinter, respectivamente.
     * 
     * @return mixed
     */
    public function __construct()
    {
        $this->oFind = new NodeFinder();
        $this->oPP = new PrettyPrinter();
    }

    /**
     * Processa um nó da árvore sintática, coletando informações sobre classes, funções e seus efeitos colaterais.
     * 
     * O método analisa o nó fornecido, extraindo detalhes como parâmetros, tipos de retorno, chamadas de função, 
     * efeitos colaterais e complexidade do código, armazenando essas informações em uma estrutura interna.
     * 
     * @param \PhpParser\Node $oNo O nó a ser analisado.
     * @return void
     */
    public function enterNode(Node $oNo): void
    {
        if ($oNo instanceof Node\Stmt\ClassLike) {
            $this->aPilhaClasse[] = $this->fqnDe($oNo);
        }

        $sRotulo = $this->rotuloDe($oNo);
        if ($sRotulo === null) {
            return;
        }

        $aEntrada = $this->entradaBase($sRotulo, $oNo);

        if ($oNo instanceof Node\FunctionLike) {
            foreach ($oNo->getParams() as $oP) {
                $aEntrada['params'][] = [
                    'name' => '$' . $oP->var->name,
                    'type' => $this->tipoParaString($oP->type) ?? 'mixed',
                    'byRef' => (bool) $oP->byRef,
                    'variadic' => (bool) $oP->variadic,
                    'default' => $oP->default ? $oP->default->getType() : null,
                    'default_str' => $oP->default ? $this->oPP->prettyPrintExpr($oP->default) : null,
                ];
            }
            $aEntrada['returnType'] = $this->tipoParaString($oNo->getReturnType()) ?? 'mixed';

            $aStmts = $oNo->getStmts() ?? [];

            $aCalls = [];
            foreach (
                array_merge(
                    $this->oFind->findInstanceOf($aStmts, FuncCall::class),
                    $this->oFind->findInstanceOf($aStmts, MethodCall::class),
                    $this->oFind->findInstanceOf($aStmts, StaticCall::class)
                ) as $oC
            ) {
                if ($oC instanceof FuncCall && $oC->name instanceof Name) {
                    $aCalls[] = $oC->name->toString();
                } elseif ($oC instanceof MethodCall && $oC->name instanceof Identifier) {
                    $aCalls[] = '$this->' . $oC->name->toString() . '()';
                } elseif ($oC instanceof StaticCall && $oC->name instanceof Identifier) {
                    $sClass = $oC->class instanceof Name ? $oC->class->toString() : 'static';
                    $aCalls[] = $sClass . '::' . $oC->name->toString() . '()';
                }
            }
            $aEntrada['chamadas'] = array_values(array_unique($aCalls));

            $aThrows = [];
            foreach ($this->oFind->findInstanceOf($aStmts, Throw_::class) as $oT) {
                $aThrows[] = $this->oPP->prettyPrintExpr($oT->expr);
            }
            $aEntrada['throws'] = array_values(array_unique($aThrows));

            $isPropTarget = static function ($var): bool {
                while ($var instanceof ArrayDimFetch) {
                    $var = $var->var;
                }
                return $var instanceof PropertyFetch || $var instanceof StaticPropertyFetch;
            };

            $bAlteraEstado = false;
            foreach (
                array_merge(
                    $this->oFind->findInstanceOf($aStmts, Assign::class),
                    $this->oFind->findInstanceOf($aStmts, AssignOp::class),
                    $this->oFind->findInstanceOf($aStmts, PreInc::class),
                    $this->oFind->findInstanceOf($aStmts, PostInc::class),
                    $this->oFind->findInstanceOf($aStmts, PreDec::class),
                    $this->oFind->findInstanceOf($aStmts, PostDec::class)
                ) as $n
            ) {
                $var = $n instanceof Assign || $n instanceof AssignOp ? $n->var : $n->var;
                if ($isPropTarget($var)) {
                    $bAlteraEstado = true;
                    break;
                }
            }

            $bUsaGlobais = !empty($this->oFind->find($aStmts, static function ($n) {
                return $n instanceof Variable
                    && is_string($n->name)
                    && in_array($n->name, ['_GET', '_POST', '_COOKIE', '_SERVER', '_FILES', '_REQUEST'], true);
            }));

            $bEscreveIO = !empty($this->oFind->find($aStmts, static function ($n) {
                if ($n instanceof Echo_ || $n instanceof Print_) {
                    return true;
                }
                if ($n instanceof FuncCall && $n->name instanceof Name) {
                    $s = strtolower($n->name->toString());
                    return in_array($s, ['printf', 'fprintf', 'file_put_contents', 'fwrite', 'error_log', 'var_dump'], true);
                }
                return false;
            }));

            $aWhitelistPura = [
                'count',
                'sizeof',
                'in_array',
                'array_key_exists',
                'is_string',
                'is_int',
                'is_float',
                'is_bool',
                'is_null',
                'strlen',
                'substr',
                'implode',
                'explode',
                'trim',
                'ltrim',
                'rtrim',
                'json_encode',
                'json_decode'
            ];
            $bChamaExternos = !empty($this->oFind->find($aStmts, static function ($n) use ($aWhitelistPura) {
                if ($n instanceof FuncCall && $n->name instanceof Name) {
                    return !in_array(strtolower($n->name->toString()), $aWhitelistPura, true);
                }
                if ($n instanceof MethodCall) {
                    return !($n->var instanceof Variable && $n->var->name === 'this');
                }
                if ($n instanceof StaticCall) {
                    return !($n->class instanceof Name && in_array(strtolower($n->class->toString()), ['self', 'static'], true));
                }
                return false;
            }));

            $aEntrada['efeitos_colaterais'] = [
                'altera_estado' => $bAlteraEstado,
                'usa_globais' => $bUsaGlobais,
                'escreve_io' => $bEscreveIO,
                'chama_externos' => $bChamaExternos,
            ];

            // Operadores (sem Reflection)
            $aOps = [];
            foreach ($this->oFind->findInstanceOf($aStmts, BinaryOp::class) as $oOp) {
                $cls = get_class($oOp);
                $aOps[] = ($p = strrpos($cls, '\\')) !== false ? substr($cls, $p + 1) : $cls;
            }
            $aOps = array_values(array_unique($aOps));
            $aEntrada['operadores'] = $aOps;
            $aEntrada['operacao_principal'] = (count($aOps) === 1) ? $aOps[0] : null;

            $iRetCount = 0;
            $aRetKinds = [];
            foreach ($this->oFind->findInstanceOf($aStmts, Return_::class) as $oR) {
                $iRetCount++;
                $aRetKinds[] = $oR->expr ? $oR->expr->getType() : 'void';
            }
            $aEntrada['retornos'] = [
                'caminhos' => $iRetCount,
                'tipos_de_retorno_encontrados' => array_values(array_unique($aRetKinds)),
            ];

            $aEntrada['complexidade'] = [
                'if' => count($this->oFind->findInstanceOf($aStmts, If_::class)),
                'loops' => count($this->oFind->find($aStmts, fn($n) => $n instanceof For_ || $n instanceof Foreach_ || $n instanceof While_ || $n instanceof Do_)),
                'catch' => count($this->oFind->findInstanceOf($aStmts, Catch_::class)),
                'ternary' => count($this->oFind->findInstanceOf($aStmts, Ternary::class)),
                'match' => count($this->oFind->findInstanceOf($aStmts, Match_::class)),
            ];

            $bDivZero = false;
            foreach ($this->oFind->findInstanceOf($aStmts, OpDiv::class) as $div) {
                if ($div->right instanceof LNumber && $div->right->value === 0) {
                    $bDivZero = true;
                    break;
                }
            }
            $bNullChecks = !empty($this->oFind->find($aStmts, static function ($n) {
                if ($n instanceof \PhpParser\Node\Expr\BinaryOp\Coalesce) {
                    return true;
                }
                if ($n instanceof FuncCall && $n->name instanceof Name && strtolower($n->name->toString()) === 'is_null') {
                    return true;
                }
                if ($n instanceof \PhpParser\Node\Expr\BinaryOp\Identical || $n instanceof \PhpParser\Node\Expr\BinaryOp\NotIdentical) {
                    return ($n->left instanceof \PhpParser\Node\Expr\ConstFetch && strtolower($n->left->name->toString()) === 'null')
                        || ($n->right instanceof \PhpParser\Node\Expr\ConstFetch && strtolower($n->right->name->toString()) === 'null');
                }
                return false;
            }));
            $bEmptyIsset = !empty($this->oFind->find($aStmts, fn($n) => $n instanceof Empty_ || $n instanceof Isset_));

            $aEntrada['checagens'] = [
                'divisao_por_zero' => $bDivZero,
                'null_checks' => $bNullChecks,
                'empty_isset' => $bEmptyIsset,
            ];
        }

        $this->aItens[] = $aEntrada;
    }

    /**
     * Remove a última classe da pilha se o nó fornecido for uma declaração de classe.
     * 
     * @param \PhpParser\Node $oNo O nó a ser analisado.
     * @return void
     */
    public function leaveNode(Node $oNo): void
    {
        if ($oNo instanceof Node\Stmt\ClassLike) {
            array_pop($this->aPilhaClasse);
        }
    }

    /**
     * Retorna o rótulo correspondente ao tipo de nó fornecido.
     * 
     * @param Node $oNo O nó do qual se deseja obter o rótulo.
     * @return ?string O rótulo do tipo de nó, ou null se o tipo não for reconhecido.
     */
    private function rotuloDe(Node $oNo): ?string
    {
        if ($oNo instanceof Node\Stmt\Class_) {
            return 'class';
        }
        if ($oNo instanceof Node\Stmt\Interface_) {
            return 'interface';
        }
        if ($oNo instanceof Node\Stmt\Trait_) {
            return 'trait';
        }
        if ($oNo instanceof Node\Stmt\Enum_) {
            return 'enum';
        }
        if ($oNo instanceof Node\Stmt\Function_) {
            return 'function';
        }
        if ($oNo instanceof Node\Stmt\ClassMethod) {
            return 'method';
        }
        if ($oNo instanceof Node\Stmt\Property) {
            return 'property';
        }
        if ($oNo instanceof Node\Stmt\ClassConst) {
            return 'constant';
        }
        return null;
    }

    
    /**
     * Gera uma estrutura de dados base para um nó de documentação, incluindo informações como tipo, nome, FQN e comentários.
     * 
     * @param string $sRotulo O rótulo que define o tipo do nó.
     * @param PhpParser\Node $oNo O nó do qual as informações serão extraídas.
     * @return array Um array contendo as informações estruturadas do nó de documentação.
     */
    private function entradaBase(string $sRotulo, Node $oNo): array
    {
        $oDoc = $oNo->getDocComment();

        $aBase = [
            'id' => 'doc_' . $this->iProxId++,
            'type' => $sRotulo,
            'name' => $this->nomeCurtoDe($oNo),
            'fqn' => $this->fqnDe($oNo),
            'doc' => $oDoc ? $oDoc->getText() : null,
            'doc_start' => $oDoc instanceof Doc ? $oDoc->getStartLine() : null,
            'doc_end' => $oDoc instanceof Doc ? $oDoc->getEndLine() : null,
            'params' => [],
            'returnType' => null,
            'line' => $oNo->getStartLine(),
            'endLine' => $oNo->getEndLine(),
            'loc' => $oNo->getEndLine() - $oNo->getStartLine() + 1,
            'modificadores' => [],
            'atributos' => [],
        ];

        if ($oNo instanceof Class_) {
            $aBase['heranca'] = [
                'extends' => isset($oNo->extends) ? $oNo->extends->toString() : null,
                'implements' => array_map(fn($n) => $n->toString(), $oNo->implements),
            ];
            $aBase['tipos_uso'] = array_map(
                fn(TraitUse $t) => array_map(fn($n) => $n->toString(), $t->traits),
                array_filter($oNo->stmts, fn($s) => $s instanceof TraitUse)
            );
        }

        $aBase['modificadores'] = [
            'public' => method_exists($oNo, 'isPublic') ? $oNo->isPublic() : false,
            'protected' => method_exists($oNo, 'isProtected') ? $oNo->isProtected() : false,
            'private' => method_exists($oNo, 'isPrivate') ? $oNo->isPrivate() : false,
            'static' => method_exists($oNo, 'isStatic') ? $oNo->isStatic() : false,
            'final' => method_exists($oNo, 'isFinal') ? $oNo->isFinal() : false,
            'abstract' => method_exists($oNo, 'isAbstract') ? $oNo->isAbstract() : false,
        ];

        foreach ($oNo->attrGroups ?? [] as $oG) {
            foreach ($oG->attrs as $oA) {
                $aBase['atributos'][] = $oA->name->toString();
            }
        }

        return $aBase;
    }

    /**
     * Retorna o nome curto de um nó, se disponível.
     * 
     * Verifica se o nó possui uma propriedade 'name' e se ela é uma instância de Identifier ou Name, retornando o nome apropriado.
     * 
     * @param PhpParser\Node $oNo O nó a ser analisado.
     * @return ?string O nome curto do nó ou null se não estiver disponível.
     */
    private function nomeCurtoDe(Node $oNo): ?string
    {
        if (property_exists($oNo, 'name') && $oNo->name instanceof Identifier) {
            return $oNo->name->name;
        }
        if (property_exists($oNo, 'name') && $oNo->name instanceof Name) {
            return $oNo->name->toString();
        }
        return null;
    }
    /**
     * Retorna a representação totalmente qualificada de um nó, se disponível.
     * 
     * @param PhpParser\Node $oNo O nó a ser analisado para obter o nome qualificado.
     * @return ?string A representação totalmente qualificada do nó ou o nome curto se não estiver disponível.
     */
    private function fqnDe(Node $oNo): ?string
    {
        if (property_exists($oNo, 'namespacedName') && $oNo->namespacedName) {
            return $oNo->namespacedName->toString();
        }
        if ($oNo instanceof Node\Stmt\ClassMethod) {
            $sClasse = end($this->aPilhaClasse) ?: null;
            $sMetodo = $oNo->name->toString();
            return $sClasse ? ($sClasse . '::' . $sMetodo) : $sMetodo;
        }
        return $this->nomeCurtoDe($oNo);
    }

    /**
     * Converte um tipo em sua representação de string correspondente.
     * 
     * @param mixed $mTipo O tipo a ser convertido.
     * @return ?string A representação em string do tipo, ou null se o tipo for nulo.
     */
    private function tipoParaString($mTipo): ?string
    {
        if ($mTipo === null) {
            return null;
        }
        if ($mTipo instanceof Node\NullableType) {
            return '?' . $this->tipoParaString($mTipo->type);
        }
        if ($mTipo instanceof Node\UnionType) {
            return implode('|', array_map(fn($t) => $this->tipoParaString($t), $mTipo->types));
        }
        if ($mTipo instanceof Node\IntersectionType) {
            return implode('&', array_map(fn($t) => $this->tipoParaString($t), $mTipo->types));
        }
        if ($mTipo instanceof Identifier || $mTipo instanceof Name) {
            return $mTipo->toString();
        }
        return (string) $mTipo;
    }
}
