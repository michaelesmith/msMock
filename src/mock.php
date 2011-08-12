<?php
/**
 * msMock Library to mock or stub objects in unit testing
 *
 * @author msmith
 * 
 * @see msMockClass::__construct() for options
 * 
 * @example - if you want o check that your class makes a couple of calls to a given object
 * $t = new lime_test(null);
 * 
 * $subject = new msMock($t, array('from_class' => 'OriginalSubject'));
 * $subject->add(1, 2)->called(2)->returns(3);
 * $subject->subtract(5, 4)->called(1)->returns(1);
 * $subject->__replay(); // let the mock object know we are done recording behaviours
 * 
 * $your_class = new YourClass($subject);
 * $your_class->doSomething();
 * 
 * $locator->__verify(); // all our expectations will be verified
 * 
 * $locator->__analyze(); // puts the mock in analyze mode
 * $locator->__calls(); // returns all calls made
 * $locator->add(); // returns all calls made to add()
 * $locator->add()->count();
 * $locator->add()->first()->arguments[0];
 * $locator->add()->offsetGet(0)->arguments[0];
 * 
 */


/**
 * msMock
 * 
 * @author msmith
 */
class msMock{
	/**
	 * @var msMockClass
	 */
	public $__mocker;

	/**
	 * Constructor
	 * 
	 * @param lime_test $t
	 * @param array $options
	 */
	public function __construct($t, $options = array()){
		$this->__mocker = new msMockClass($t, $options);
	}

	/**
	 * Catches calls to undefefined methods and forwards to our mocker class
	 * 
	 * @param str $name
	 * @param array $arguments
	 * @return mixed 
	 */
	public function __call($name, $arguments){
		return $this->__mocker->call($name, $arguments);
	}

	/**
	 * Puts the mock object in replay mode ready for use
	 */
	public function __replay(){
		$this->__mocker->replay();
	}

	/**
	 * Puts the mock object in analyze mode where the call log for a function is returned
	 */
	public function __analyze(){
		$this->__mocker->analyze();
	}

	/**
	 * Returns all calls made to the mock
	 * 
	 * @return msMockMethodCallCollection
	 */
	public function __calls(){
		return $this->__mocker->getCalls();
	}

	/**
	 * Resets the method call log
	 */
	public function __reset(){
		$this->__mocker->resetCalls();
	}

	/**
	 * Verifies all of our recorded expectations
	 */
	public function __verify(){
		$this->__mocker->verify();
	}
}

/**
 * msMockClass
 * 
 * @author msmith
 */
class msMockClass{
	protected $mode = 0;
	protected $methods = array();
	protected $calls = array();
	protected $t;
	protected $options = array(
						'check_arguments_count' => false, // calls must have the same number of arguments as recorded
						'check_arguments_types' => false, // each argument must match the type recorded
						'check_arguments_values' => false, // each argument must match the value recorded
						'stub_all' => false, // calls to unknown methods return null and are recorded
						'from_class' => false, // creates the mock from a class and allows for further recording
						'extra_methods' => false, // allow extra methods if created from a class
						'inherited_methods' => false, // if false only mocks methods directly from the class
						'extra_calls' => false, // if false verify() will fail with calls to unexpected methods
				  );


	/**
	 * Constructor
	 * 
	 * @param lime_test $tester
	 * @param array $options 
	 */
	public function __construct($tester, $options = array()){
		$this->t = $tester;
		
		foreach($options as $option => $value){
			if(isset($this->options[$option])){
				$this->options[$option] = $value;
			}else{
				throw new RuntimeException(sprintf('Unknown option: "%s"', $option));
			}
		}

		if($this->options['from_class']){
			$this->fromClass($this->options['from_class']);
		}
	}

	/**
	 * Adds all of the methods from an existiing class.
	 * 
	 * @note expectations will still need to be st
	 * 
	 * @param str $class 
	 */
	public function fromClass($class){
		if($this->mode != 0){
			throw new RuntimeException('fromClass() is only available in record mode');
		}
		$reflection = new ReflectionClass($class);
		foreach($reflection->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_FINAL) as $method){
			if($this->options['inherited_methods'] || $method->class == $class){
				$this->doAddMethod($method->name, array());
			}
		}
	}

	/**
	 * Performs the addition of a method
	 * 
	 * @throws RuntimeException if you try to add method once the mock is out of record mode
	 * 
	 * @param str $name
	 * @param array $arguments
	 * @return msMockMethod 
	 */
	protected function doAddMethod($name, $arguments){
		if($this->mode != 0){
			throw new RuntimeException('addMethod() is only available in record mode');
		}

		return $this->methods[$name] = new msMockMethod($arguments, $this->options);
	}

	/**
	 * Adds a method to the mock
	 * 
	 * @throws RuntimeException if you try to conbfigure extra methods with extra_methods=false
	 * 
	 * @param str $name
	 * @param array $arguments
	 * @return msMockMethod 
	 */
	public function addMethod($name, $arguments){
		if(!$this->options['from_class'] || $this->options['extra_methods'] || $this->hasMethod($name)){
			return $this->doAddMethod($name, $arguments);
		}else{
			throw new RuntimeException('Extra methods can not be added when extra_methods = false');
		}
	}

	/**
	 * Fetches the return value and compares argumnets to expected if configured
	 * 
	 * @param str $name
	 * @param array $arguments
	 * @return mixed
	 */
	public function playMethod($name, $arguments){
		if($this->hasMethod($name)){
			$this->calls[] = new msMockMethodCall($name, $this->methods[$name], $arguments);

			return $this->methods[$name]->play($arguments, $this->t);
		}elseif($this->options['stub_all']){
			$this->calls[] = new msMockMethodCall($name, null, $arguments);

			return null;
		}else{
			$this->t->fail(sprintf('call to unknown method: %s()', $name));

			return null;
		}
	}

	/**
	 * Calls a mock method
	 * 
	 * @param str $name
	 * @param array $arguments
	 * @return mixed 
	 */
	public function call($name, $arguments){
		switch($this->mode){
			case 0:
				return $this->addMethod($name, $arguments);
			case 1:
				return $this->playMethod($name, $arguments);
			case 2:
				return $this->getCallsFor($name);
			default:
				throw new RuntimeException(sprintf('Unknown mode %s only 0, 1, 2 is used', $this->mode));
				break;
		}

	}

	/**
	 * Returns true if the given method has been added
	 * 
	 * @param str $name
	 * @return boolean 
	 */
	public function hasMethod($name){
		return isset($this->methods[$name]);
	}

	/**
	 * Resets the method call log
	 */
	public function resetCalls(){
		$this->calls = array();
	}

	/**
	 * Puts the mock object in replay mode ready for use
	 */
	public function replay(){
		$this->mode = 1;
	}

	/**
	 * Puts the mock object in analyze mode where the call log for a function is returned
	 */
	public function analyze(){
		$this->mode = 2;
	}

	/**
	 * Verifies all of our recorded expectations
	 */
	public function verify(){
		if($this->mode == 0){
			throw new RuntimeException('cannot call verify() with mock object in record mode');
		}

		$expected = 0;
		foreach($this->methods as $name => $method){
			if($method->number_of_calls > -1){
				$this->t->is($my_count = $this->getCallsFor($name)->count(), $method->number_of_calls, sprintf('%s() expects %d calls', $name, $method->number_of_calls));
				$expected += $my_count;
			}
		}

		if(!$this->options['extra_calls'] && $expected != $this->getCalls()->count()){
			$this->t->fail('unexpected methods were called');
		}
	}

	/**
	 * Returns all calls made to the mock for a given method
	 *
	 * @return msMockMethodCallCollection
	 */
	public function getCallsFor($name){
		return $this->getCalls()->findByName($name);
	}

	/**
	 * Returns all calls made to the mock
	 * 
	 * @return msMockMethodCallCollection 
	 */
	public function getCalls(){
		return new msMockMethodCallCollection($this->calls);
	}

	/**
	 * Calls a method on all the objects given
	 * 
	 * @param $method
	 * @param $arguments
	 * @param $objects
	 */
	public static function callAll($method, $arguments, $objects){
		foreach($objects as $object){
			call_user_func_array(array($object, $method), $arguments);
		}
	}

	/**
	 * Call ->__replay() on all objects
	 *
	 * @param $object
	 * @param $object
	 */
	public static function replayAll(){
		self::callAll('__replay', array(), func_get_args());
	}

	/**
	 * Call ->__reset() on all objects
	 * 
	 * @param $object
	 * @param $object
	 */
	public static function resetAll(){
		self::callAll('__reset', array(), func_get_args());
	}

	/**
	 * Call ->__verify() on all objects
	 *
	 * @param $object
	 * @param $object
	 */
	public static function verifyAll(){
		self::callAll('__verify', array(), func_get_args());
	}
}

/**
 * msMockMethod
 * 
 * @author msmith
 */
class msMockMethod{
	public $arguments = array();
	public $return = null;
	public $number_of_calls = -1;
	public $options = array(
						'check_arguments_count' => false, // calls must have the same number of arguments as recorded
						'check_arguments_types' => false, // each argument must match the type recorded
						'check_arguments_values' => false, // each argument must match the value recorded
						'match_arguments' => false, // allows multiple calls to the method but returns based on the arguments overides all check_argumnets_* options
				  );

	/**
	 * Constructor
	 * 
	 * @param array $arguments 
	 * @param array $options
	 */
	public function __construct($arguments, $options = array()){
		$this->arguments = $arguments;

		foreach($options as $option => $value){
			if(isset($this->options[$option])){
				$this->options[$option] = $value;
			}
		}
	}

	/**
	 * Sets the expected arguments.
	 * 
	 * Can be called multiple times if match_arguments=true
	 * 
	 * @return msMockMethod 
	 */
	public function arguments(){
		if($this->options['match_arguments']){
			$this->arguments[] = func_get_args();
		}else{
			$this->arguments = func_get_args();
		}
		
		return $this;
	}

	/**
	 * Sets the return value of the method
	 * 
	 * Can be called multiple times if match_arguments=true
	 * 
	 * @param mixed $value
	 * @return msMockMethod 
	 */
	public function returns($value){
		if($this->options['match_arguments']){
			$this->return[] = $value;
		}else{
			$this->return = $value;
		}

		return $this;
	}

	/**
	 * Sets the expectation for the number of calls
	 * 
	 * @param int $number_of_calls
	 * @return msMockMethod 
	 */
	public function called($number_of_calls){
		$this->number_of_calls = $number_of_calls;

		return $this;
	}

	/**
	 * Sets an option on this method instance
	 *
	 * @throws RuntimeException for invlaid option
	 * 
	 * @param str $option
	 * @param mixed $value
	 * @return msMockMethod 
	 */
	public function setOption($option, $value){
		if(!isset($this->options[$option])){
			throw new RuntimeException(sprintf('Invalid option: "%s"', $option));
		}

		$this->options[$option] = $value;

		return $this;
	}

	/**
	 * Returns the value of an option on this method instance
	 * 
	 * @throws RuntimeException for invlaid option
	 * 
	 * @param str $option
	 * @return mixed 
	 */
	public function getOption($option){
		if(isset($this->options[$option])){
			return $this->options[$option];
		}else{
			throw new RuntimeException(sprintf('Invalid option: "%s"', $option));
		}
	}

	/**
	 * Returns the configured value(s) and performs configured checks
	 * 
	 * @param array $arguments
	 * @param lime_test $tester
	 * @return mixed
	 */
	public function play($arguments, $tester){
		if($this->options['match_arguments']){
			if(($key = array_search($arguments, $this->arguments)) !== false){
				return $this->return[$key];
			}else{
				$tester->fail(sprintf('no return value for arguments: ', var_export($arguments, true)));

				return null;
			}
		}

		if($this->options['check_arguments_count']){
			$tester->is(count($arguments), count($this->arguments), 'must have the same number of arguments');
		}

		if($this->options['check_arguments_types']){
			foreach($arguments as $key => $argument){
				if(is_object($argument)){
					$tester->is(get_class($argument), get_class($this->arguments[$key]), sprintf('argument %d: classes must match', $key));
				}else{
					$tester->is(gettype($argument), gettype($this->arguments[$key]), sprintf('argument %d: types must match', $key));
				}
			}
		}

		if($this->options['check_arguments_values']){
			foreach($arguments as $key => $argument){
				$tester->is($argument, $this->arguments[$key], sprintf('argument %d: values must match', $key));
			}
		}

		return $this->return;
	}
}

/**
 * msMockMethodCall
 * 
 * @author msmith
 */
class msMockMethodCall{
	public $name;
	public $method;
	public $arguments;
	public $trace;
	public $time;

	/**
	 * Constructor
	 * 
	 * @param str $name
	 * @param msMockMethod $method
	 * @param array $arguments 
	 */
	public function __construct($name, $method, $arguments){
		$this->name = $name;
		$this->method = $method;
		$this->arguments = $arguments;
		$this->trace = debug_backtrace();
		$this->time = time();

		//remove our own presence from the trace
		for($i = 0; $i < 4; $i++){
			array_shift($this->trace);
		}
	}
}

/**
 * msMockMethodCallCollection
 * 
 * @author msmith
 */
class msMockMethodCallCollection implements Countable, ArrayAccess {
	public $calls;

	/**
	 * Contructor
	 * 
	 * @param array $calls 
	 */
	public function __construct($calls) {
		$this->calls = $calls;
	}

	/**
	 * Returns the count of all calls in this collection
	 * 
	 * @return int
	 */
	public function count(){
		return count($this->calls);
	}

	/**
	 * Returns the first call in the collection
	 * 
	 * @return msMockMethodCall
	 */
	public function first(){
		return $this->calls[0];
	}

	/**
	 * Returns the last call in the collection
	 *
	 * @return msMockMethodCall
	 */
	public function last(){
		return $this->calls[$this->count() - 1];
	}

	/**
	 * Returns all calls as an array
	 * @return array
	 */
	public function toArray(){
		return $this->calls;
	}

	/**
	 * Returns all call made to a given method
	 * 
	 * @param str $name
	 * @return msMockMethodCallCollection 
	 */
	public function findByName($name){
		$ret_arr = array();
		foreach($this->calls as $call){
			if($call->name == $name){
				$ret_arr[] = $call;
			}
		}

		return new msMockMethodCallCollection($ret_arr);
	}

	/**
	 * Returns the call at the given offset
	 * 
	 * @param int $offset
	 * @return msMockMethodCall 
	 */
	public function offsetGet($offset) {
		return $this->calls[$offset];
	}

	/**
	 * Returns if the offset exisits in the collection
	 * 
	 * @param int $offset
	 * @return boolean
	 */
	public function offsetExists($offset) {
		return isset($this->calls[$offset]);
	}

	/**
	 * Do not use the collection is readonly
	 * 
	 * @throws RuntimeException
	 * 
	 * @param mixed $offset
	 * @param mixed $value 
	 */
	public function offsetSet($offset, $value) {
		throw new RuntimeException('Collection is readonly');
	}

	/**
	 * Do not use the collection is readonly
	 * 
	 * @throws RuntimeException
	 *
	 * @param mixed $offset 
	 */
	public function offsetUnset($offset) {
		throw new RuntimeException('Collection is readonly');
	}
}
