<?php

namespace Bolt\Storage\Query;

use Bolt\Exception\QueryParseException;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;

/**
 *  Handler class to convert the DSL for content query parameters
 *  into equivalent DBAL expressions.
 *
 *  @author Ross Riley <riley.ross@gmail.com>
 */
class QueryParameterParser
{
    /** @var string */
    public $alias;

    /** @var string */
    protected $key;
    /** @var mixed */
    protected $value;
    /** @var ExpressionBuilder */
    protected $expr;
    /** @var array */
    protected $valueMatchers = [];
    /** @var Filter[] */
    protected $filterHandlers = [];

    /**
     * Constructor.
     *
     * @param ExpressionBuilder $expr
     */
    public function __construct(ExpressionBuilder $expr = null)
    {
        $this->expr = $expr;
        $this->setupDefaults();
    }

    public function setupDefaults()
    {
        // @codingStandardsIgnoreStart
        $this->addValueMatcher('<(\w+)',            ['value' => '$1', 'operator' => 'lt']);
        $this->addValueMatcher('<=(\w+)',           ['value' => '$1', 'operator' => 'lte']);
        $this->addValueMatcher('>=(\w+)',           ['value' => '$1', 'operator' => 'gte']);
        $this->addValueMatcher('>(\w+)',            ['value' => '$1', 'operator' => 'gt']);
        $this->addValueMatcher('!$',                ['value' => '',   'operator' => 'isNotNull']);
        $this->addValueMatcher('!(\w+)',            ['value' => '$1', 'operator' => 'neq']);
        $this->addValueMatcher('!\[([\w ,]+)\]',    ['value' => function ($val) { return explode(',', $val); }, 'operator' => 'notIn']);
        $this->addValueMatcher('\[([\w ,]+)\]',     ['value' => function ($val) { return explode(',', $val); }, 'operator' => 'in']);
        $this->addValueMatcher('(%\w+|\w+%|%\w+%)', ['value' => '$1', 'operator' => 'like']);
        $this->addValueMatcher('(\w+)',             ['value' => '$1', 'operator' => 'eq']);
        // @codingStandardsIgnoreEnd

        $this->addFilterHandler([$this, 'defaultFilterHandler']);
        $this->addFilterHandler([$this, 'multipleValueHandler']);
        $this->addFilterHandler([$this, 'multipleKeyAndValueHandler']);
        $this->addFilterHandler([$this, 'incorrectQueryHandler']);
    }

    /**
     * Sets the select alias to be used in sql queries.
     *
     * @param string $alias
     */
    public function setAlias($alias)
    {
        $this->alias = $alias . '.';
    }

    /**
     * Runs the keys/values through the relevant parsers.
     *
     * @param string $key
     * @param mixed  $value
     * @param mixed  $value
     *
     * @throws QueryParseException
     * @throws QueryParseException
     *
     * @return Filter|null
     */
    public function getFilter($key, $value = null)
    {
        if (!$this->expr instanceof ExpressionBuilder) {
            throw new QueryParseException('Cannot call method without an Expression Builder parameter set', 1);
        }

        /** @var callable $callback */
        foreach ($this->filterHandlers as $callback) {
            $result = $callback($key, $value, $this->expr);
            if ($result instanceof Filter) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Handles some errors in key/value string formatting.
     *
     * @param string            $key
     * @param string            $value
     * @param ExpressionBuilder $expr
     *
     * @throws QueryParseException
     */
    public function incorrectQueryHandler($key, $value, $expr)
    {
        if (strpos($value, '&&') && strpos($value, '||')) {
            throw new QueryParseException('Mixed && and || operators are not supported', 1);
        }
    }

    /**
     * This handler processes 'triple pipe' queries as implemented in Bolt
     * It looks for three pipes in the key and value and creates an OR composite
     * expression for example: 'username|||email':'fred|||pete'.
     *
     * @param string            $key
     * @param string            $value
     * @param ExpressionBuilder $expr
     *
     * @return Filter|null
     */
    public function multipleKeyAndValueHandler($key, $value, $expr)
    {
        if (!strpos($key, '|||')) {
            return null;
        }

        $keys = preg_split('/ *(\|\|\|) */', $key);
        $inputKeys = $keys;
        $values = preg_split('/ *(\|\|\|) */', $value);
        $values = array_pad($values, count($keys), end($values));

        $filterParams = [];
        $parts = [];
        $count = 1;

        while (($key = array_shift($keys)) && ($val = array_shift($values))) {
            $multipleValue = $this->multipleValueHandler($key, $val, $this->expr);
            if ($multipleValue) {
                $filter = $multipleValue->getExpression();
                $filterParams = $filterParams + $multipleValue->getParameters();
            } else {
                $val = $this->parseValue($val);
                $placeholder = $key . '_' . $count;
                $filterParams[$placeholder] = $val['value'];
                $exprMethod = $val['operator'];
                $filter = $this->expr->$exprMethod($this->alias . $key, ':' . $placeholder);
            }

            $parts[] = $filter;
            ++$count;
        }

        $filter = new Filter();
        $filter->setKey($inputKeys);
        $filter->setExpression(call_user_func_array([$expr, 'orX'], $parts));
        $filter->setParameters($filterParams);

        return $filter;
    }

    /**
     * This handler processes multiple value queries as defined in the Bolt 'Fetching Content'
     * documentation. It allows a value to be parsed to and AND/OR expression.
     *
     * For example, this handler will correctly parse values like:
     *     'username': 'fred||bob'
     *     'id': '<5 && !1'
     *
     * @param string            $key
     * @param string            $value
     * @param ExpressionBuilder $expr
     *
     * @return Filter|null
     */
    public function multipleValueHandler($key, $value, $expr)
    {
        if (strpos($value, '&&') === false && strpos($value, '||') === false) {
            return null;
        }

        $values = preg_split('/ *(&&|\|\|) */', $value, -1, PREG_SPLIT_DELIM_CAPTURE);
        $op = $values[1];

        if ($op === '&&') {
            $comparison = 'andX';
        } elseif ($op === '||') {
            $comparison = 'orX';
        }

        $values = array_diff($values, ['&&', '||']);

        $filterParams = [];
        $parts = [];
        $count = 1;

        while ($val = array_shift($values)) {
            $val = $this->parseValue($val);
            $placeholder = $key . '_' . $count;
            $filterParams[$placeholder] = $val['value'];
            $exprMethod = $val['operator'];
            $parts[] = $this->expr->$exprMethod($this->alias . $key, ':' . $placeholder);
            ++$count;
        }

        $filter = new Filter();
        $filter->setKey($key);
        $filter->setExpression(call_user_func_array([$expr, $comparison], $parts));
        $filter->setParameters($filterParams);

        return $filter;
    }

    /**
     * The default handler is the last to be run and handler simple value parsing.
     *
     * @param string            $key
     * @param string            $value
     * @param ExpressionBuilder $expr
     *
     * @return Filter
     */
    public function defaultFilterHandler($key, $value, $expr)
    {
        $val = $this->parseValue($value);
        $placeholder = $key . '_1';
        $exprMethod = $val['operator'];

        $filter = new Filter();
        $filter->setKey($key);
        $filter->setExpression($expr->andX($expr->$exprMethod($this->alias . $key, ':' . $placeholder)));
        $filter->setParameters([$placeholder => $val['value']]);

        return $filter;
    }

    /**
     * This method uses the defined value matchers to parse a passed in value.
     *
     * The following component parts will be returned in the array:
     * [
     *     'value'    => <the value remaining after the parse>
     *     'operator' => <the operator that should be used>
     *     'matched'  => <the pattern that the value matched>
     * ]
     *
     * @param string $value Value to process
     *
     * @throws QueryParseException
     *
     * @return array Parsed values
     */
    public function parseValue($value)
    {
        foreach ($this->valueMatchers as $matcher) {
            $regex = sprintf('/%s/', $matcher['token']);
            $values = $matcher['params'];
            if (preg_match($regex, $value)) {
                if (is_callable($values['value'])) {
                    preg_match($regex, $value, $output);
                    $values['value'] = $values['value']($output[1]);
                } else {
                    $values['value'] = preg_replace($regex, $values['value'], $value);
                }
                $values['matched'] = $matcher['token'];

                return $values;
            }
        }

        throw new QueryParseException(sprintf('No matching value found for "%s"', $value));
    }

    /**
     * The goal of this class is to turn any key:value into a Filter class.
     * Adding a handler here will push the new filter callback onto the top
     * of the Queue along with the built in defaults.
     *
     * Note: the callback should either return nothing or an instance of
     * \Bolt\Storage\Query\Filter
     *
     * @param callable $handler
     */
    public function addFilterHandler(callable $handler)
    {
        array_unshift($this->filterHandlers, $handler);
    }

    /**
     * Adds an additional token to parse for value parameters.
     *
     * This gives the ability to define additional value -> operator matches
     *
     * @param string $token    Regex pattern to match against
     * @param array  $params   Options to provide to the matched param
     * @param bool   $priority If set item will be prepended to start of list
     */
    public function addValueMatcher($token, $params = [], $priority = null)
    {
        if ($priority) {
            array_unshift($this->valueMatchers, ['token' => $token, 'params' => $params]);
        } else {
            $this->valueMatchers[] = ['token' => $token, 'params' => $params];
        }
    }
}
