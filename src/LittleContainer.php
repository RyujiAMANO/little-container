<?php
/**
 * LittleContainer.php
 *
 * @author   Ryuji AMANO <ryuji@ryus.co.jp>
 */


namespace Ryus\LittleContainer;


class LittleContainer
{

    /**
     * @var callable[]
     */
    private static $classList = [];

    /**
     * @var array [ className => classInstance, ...]
     */
    private static $instances = [];

    /**
     * register
     *
     * @param string $abstract class name
     * @param callable $function クラスの生成に使うクロージャー等
     * @return $this
     */
    public function register($abstract, callable $function)
    {
        self::$classList[$abstract] = $function;
        return $this;
    }

    /**
     * setInstance
     *
     * @param string $abstract 登録クラス名
     * @param object $instance インスタンス
     * @return void
     */
    public function setInstance($abstract, $instance)
    {
        if (substr($abstract, 0, 1) === '\\') {
            $abstract = substr($abstract, 1);
        }
        self::$instances[$abstract] = $instance;
    }

    /**
     * injectOn @Inject アノテーションがあるプロパティにインスタンスをセットする
     * （mockだとprivate, protectedはセットできないので注意）
     *
     * @param object $instance injectする対象
     * @return void
     * @throws \ReflectionException
     */
    public function injectOn($instance)
    {
        $reflectionClass = new \ReflectionClass($instance);

        // property inject
        $properties = $reflectionClass->getProperties();
        foreach ($properties as $property) {
            $comment = $property->getDocComment();
            if (preg_match('/^\s*\*\s+@Inject(\s|$)/m', $comment)) {
                if (preg_match('/^\s*\*\s+@var\s([^\s]+)/m', $comment, $matches)) {
                    $className = $matches[1];
                    $propertyInstance = $this->get($className);
                    $property->setAccessible(true);
                    $property->setValue($instance, $propertyInstance);
                }
            }
        }
    }

    /**
     * get
     *
     * @param string $abstract class name
     * @param array $constructVars コンストラクタに渡す変数配列
     * @return object
     * @throws \ReflectionException
     */
    public function get($abstract, array $constructVars = [])
    {
        if (isset(self::$instances[$abstract])) {
            return self::$instances[$abstract];
        }

        // registerしたなかから解決
        if (isset(self::$classList[$abstract])) {
            $instance = call_user_func(self::$classList[$abstract], $this);
            self::$instances[$abstract] = $instance;
            return self::$instances[$abstract];
        }
        return $this->getInstanceAutoResolve($abstract, $constructVars);
    }

    /**
     * getInstanceAutoResolve
     *
     * @param string $abstract 取得したいインスタンスのクラス名
     * @param array $constructVars コンストラクタに渡す変数配列
     * @return object
     * @throws \LogicException
     */
    public function getInstanceAutoResolve($abstract, array $constructVars = [])
    {
        $class = new \ReflectionClass($abstract);
        // 自動解決
        $constructor = $class->getConstructor();
        if ($constructor) {
            $params = $constructor->getParameters();
            $args = [];
            foreach ($params as $param) {
                if ($paramClass = $param->getClass()) {
                    $argInstance = $this->get($paramClass->getName());
                    $args[] = $argInstance;
                } elseif (isset($constructVars[$param->getName()])) {
                    $args[] = $constructVars[$param->getName()];
                } else {
                    throw new \LogicException(
                        $abstract .
                        'は不明なコンストラクタ引数があるためインスタンス化できません' .
                        json_encode(
                            $param,
                            JSON_UNESCAPED_UNICODE
                        )
                    );
                }
            }
            $instance = $class->newInstanceArgs($args);
        } else {
            $instance = $class->newInstance();
        }
        self::$instances[$abstract] = $instance;
        return self::$instances[$abstract];
    }

}