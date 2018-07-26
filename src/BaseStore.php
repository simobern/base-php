<?php
abstract class BaseStore {

  protected $class;
  protected $db;

  protected static $instance;

  public function __construct(
    $collection = null,
    $class = null
  ) {
    if (defined('static::COLLECTION') && defined('static::MODEL')) {
      $collection = static::COLLECTION;
      $class = static::MODEL;
    }

    invariant($collection && $class, 'Collection or class not provided');

    $this->collection = $collection;
    $this->class = $class;
    $this->db = MongoInstance::get($collection);
    static::$instance = $this;
  }

  protected static function i() {
    return new static();
  }

  public function docs() {
    return $this->docs;
  }

  public static function find(array $query = [], array $fields = []) {
    $i = static::i();
    $i->docs = $i->db->find($query, $fields);
    return $i;
  }

  public static function findOne(array $query = [], array $fields = []) {
    $i = static::i();
    $class = $i->class;
    $doc = $i->db->findOne($query, $fields);
    return $i->loadModel($doc);
  }

  public static function update(
    array $query,
    array $new_object,
    array $options = []
  ) {
    $i = static::i();
    return $i->db->update($query, $new_object, $options);
  }

  public function sort(array $query)  {
    $this->docs = $this->docs->sort($query);
    return $this;
  }

  public function skip(int $skip) {
    $this->docs = $this->docs->skip($skip);
    return $this;
  }

  public function limit(int $query) {
    $this->docs = $this->docs->limit($query);
    return $this;
  }

  public function load() {
    $class = $this->class;
    foreach ($this->docs as $doc) {
      $obj = new $class($doc);
      yield $obj;
    }
  }

  public function loadModel($doc = []) {
    if (!$doc) {
      return null;
    }
    $class = $this->class;
    return new $class($doc);
  }

  public function distinct(string $key, array $query = []) {
    $docs = static::i()->db->distinct($key, $query);
    return !is_array($docs) ? [] : $docs;
  }

  public static function findById(MongoId $id) {
    return static::i()->findOne(['_id' => $id]);
  }

  public static function count(array $query = []) {
    return static::i()->db->count($query);
  }

  protected function ensureType(BaseModel $item) {
    return class_exists(static::i()->class) && is_a($item, $this->class);
  }

  public static function removeByModel(BaseModel $item) {
    if (!static::i()->ensureType($item)) {
      throw new Exception(
        'Invalid object provided, expected ' . static::i()->class);
      return false;
    }

    if ($item == null) {
      return false;
    }

    try {
      if ($item->_id === null) {
        static::i()->db->remove($item->document());
        return true;
      } else {
        return false;
      }
    } catch (MongoException $e) {
      error_log('MongoException:', $e->getMessage());
      return false;
    }
  }

  public static function remove(array $query = [], array $options = []) {
    try {
      static::i()->db->remove($query, $options);
      return true;
    } catch (MongoException $e) {
      error_log('MongoException:', $e->getMessage());
      return false;
    }
  }

  public static function removeById(MongoId $id) {
    return static::i()->remove(['_id' => $id]);
  }

  public function aggregate(BaseAggregation $aggregation) {
    return call_user_func_array(
      [static::i()->db, 'aggregate'],
      $aggregation->getPipeline());
  }

  public function mapReduce(
    MongoCode $map,
    MongoCode $reduce,
    array $query = null,
    array $config = null
  ) {
    $options = [
      'mapreduce' => static::i()->collection,
      'map' => $map,
      'reduce' => $reduce,
      'out' => ['inline' => true]];

    if ($query) {
      $options['query'] = $query;
    }

    if ($config) {
      unset($options['mapreduce']);
      unset($options['map']);
      unset($options['reduce']);
      unset($options['query']);
      $options = array_merge($options, $config);
    }

    $res = MongoInstance::get()->command($options);

    if (idx($res, 'ok')) {
      return $res;
    } else {
      error_log('MapReduce error:', $res);
      return null;
    }
  }

  public static function save(BaseModel &$item) {
    if (!static::i()->ensureType($item)) {
      throw new Exception(
        'Invalid object provided, expected ' . static::i()->class);
      return false;
    }

    if ($item == null) {
      return false;
    }

    try {
      if ($item->_id === null) {
        $id = new MongoId();
        $item->_id = $id;
        $document = $item->document();
        static::i()->db->insert($document);
      } else {
        $document = $item->document();
        static::i()->db->save($document);
      }
      return true;
    } catch (MongoException $e) {
      invariant_violation($e->getMessage());
      return false;
    }
    return true;
  }
}

class BaseStoreCursor {
  protected
    $class,
    $count,
    $cursor,
    $next;

  public function __construct($class, $count, $cursor, $skip, $limit) {
    $this->class = $class;
    $this->count = $count;
    $this->cursor = $cursor;
    $this->next = $count > $limit ? $skip + 1 : null;
  }

  public function count() {
    return $this->count;
  }

  public function nextPage() {
    return $this->next;
  }

  public function docs() {
    $class = $this->class;
    foreach ($this->cursor as $entry) {
      $obj = new $class($entry);
      yield $obj;
    }
  }
}

class BaseAggregation {
  protected $pipeline;
  public function __construct() {
    $this->pipeline = [];
  }

  public function getPipeline() {
    return $this->pipeline;
  }

  public function project(array $spec) {
    if (!empty($spec)) {
      $this->pipeline[] = ['$project' => $spec];
    }
    return $this;
  }

  public function match(array $spec) {
    if (!empty($spec)) {
      $this->pipeline[] = ['$match' => $spec];
    }
    return $this;
  }

  public function limit($limit) {
    $this->pipeline[] = ['$limit' => $limit];
    return $this;
  }

  public function skip($skip) {
    $this->pipeline[] = ['$skip' => $skip];
    return $this;
  }

  public function unwind($field) {
    $this->pipeline[] = ['$unwind' => '$' . $field];
    return $this;
  }

  public function group(array $spec) {
    if (!empty($spec)) {
      $this->pipeline[] = ['$group' => $spec];
    }
    return $this;
  }

  public function sort(array $spec) {
    if (!empty($spec)) {
      $this->pipeline[] = ['$sort' => $spec];
    }
    return $this;
  }

  public static function addToSet(string $field) {
    return ['$addToSet' => '$' . $field];
  }

  public static function sum($value = 1) {
    if (!is_numeric($value)) {
      $value = '$' . $value;
    }
    return ['$sum' => $value];
  }

  public function __call($name, $args) {
    $field = array_pop($args);
    switch ($name) {
      case 'addToSet':
      case 'first':
      case 'last':
      case 'max':
      case 'min':
      case 'avg':
        return ['$' . $name => '$' . $field];
    }

    throw new RuntimeException('Method not found: ' . $name);
  }

  public function push($field) {
    if (is_array($field)) {
      foreach ($field as &$f) {
        $f = '$' . $f;
      }
    } else {
      $field = '$' . $field;
    }

    return ['$push' => $field];
  }
}

function type() {
  return new BaseType();
}

final class BaseType {
  protected $type = null;
  protected $isArray = false;
  protected $isNullable = false;
  protected $ref;
  
  public function __get($name) {
    switch ($name) {
      case 'array':
      case 'nullable':
        $var = 'is' . ucfirst($name);
        $this->$var = true;
        return $this;
      case 'Int':
      case 'String':
      case 'Bool':
      case 'Float':
      case 'Double':
      case 'Any':
        $this->type = strtolower($name);
        return $this;
      case 'type':
        return $this->type;
      default:
        invariant(class_exists($name), 'Class %s does not exist', $name);
        invariant(
          is_subclass_of($name, BaseModel::class) || 
          is_subclass_of($name, BaseEnum::class) ||
          $name === MongoId::class ||
          $name === MongoDate::class,
          'Invalid custom type: %s must be BaseModel, BaseEnum, MongoId or MongoDate',
          $name);
        invariant($this->type === null, 'You already set a type for this field');
        $this->type = $name;
        return $this;
    }
  }
  
  public function ref($model) {
    invariant(
      is_a($model, BaseModel::class),
      'Invalid model: %s must be an instance of BaseModel',
      get_class_name($model));
    $this->ref = new BaseRef($model);
  }
  
  protected function checkType($value) {
    switch ($this->type) {
      case 'int':
      case 'string':
      case 'bool':
      case 'float':
      case 'double':
        invariant(
          call_user_func('is_' . $this->type, $value),
          'Type error: expected %s',
          $this->type);
        break;
      default:
        invariant(
          is_a($value, $this->type),
          'Type error: expected %s',
          $this->type);
    }
    return true;
  }
  
  public function check($value) {
    if ($value === null) {
      invariant($this->isNullable === true, 'Type error: field is not nullable');
      return true;
    }

    if ($this->isArray) {
      invariant(is_array($value), 'Type error: value is not array');
      array_map([$this, 'checkType'], $value);
      return true;
    }

    if ($this->ref) {
      invariant(idx($this->ref->document(), '__ref'), 'No BaseRef set');
    }
    
    if ($this->type === 'Any') {
      return true;
    }

    $this->checkType($value);    
    return true;
  }
}

abstract class BaseModel {
  private $__model;
  private $__types;
  private $__values;
  private $typesDeclared = false;
  
  abstract public function declareTypes();
  public function init() {}
  
  final public function __construct($document = []) {
    $this->declareTypes();
    $this->_id = type()->MongoId;
    $this->typesDeclared = true;
    $this->init();
    $this->__model = get_called_class();
    
    foreach ($document as $key => $value) {
      if (idx($this->__types, $key)) {        
        $this->$key = $key == '_id' ? mid($value) : $value;

        if (is_array($value)) {
          if (idx($value, '__model') && !idx($value, '__ref')) {
            $model_name = idx($value, '__model');
            $model = new $model_name($value);
            $this->$key = $model;
          } elseif (idx($value, '__ref')) {
            $model_name = idx($value, '__model');
            $model = new $model_name();
            $model->_id = idx($value, '_id');
            $this->$key = BaseRef::fromModel($model);
          } else {
            $refs = [];
            foreach ($value as $k => $v) {
              if (idx($v, '__model') && !idx($v, '__ref')) {
                $model_name = idx($v, '__model');
                $model = new $model_name($v);
                $refs[$k] = $model;
              } elseif (idx($v, '__ref')) {
                $model_name = idx($v, '__model');
                $model = new $model_name();
                $model->_id = idx($v, '_id');
                $refs[$k] = BaseRef::fromModel($model);
              } else {
                $type = $this->types[$key];
                $refs[$k] =
                  is_subclass_of($type, BaseEnum::class) ?
                  new $type($v) :
                  $v;
              }
            }
            $this->$key = $refs;
          }
        }
      }
    }
  }

  public function __set($name, $value) {
    if ($this->typesDeclared === false) {
      $this->__types[$name] = $value;
      return;
    }

    invariant(
      idx($this->__types, $name),
      'Cannot set field %s in %s: field does not exist',
      $name,
      get_called_class());

    if ($this->__types[$name]->check($value)) {
      $this->__values[$name] = $value;
    }
    
  }

  public function __get($name) {
    invariant(
      idx($this->__types, $name),
      'Cannot set field %s in %s: field does not exist',
      $name,
      get_called_class());
    
    return $this->__values[$name] ?? null;
  }

  public function document() {
    $document = $this->__values;
    if ($document === null) {
      return new class{};
    }
    
    foreach ($document as &$item) {
      if ($item instanceof BaseRef) {
        $item = $item->document();
      } elseif ($item instanceof BaseModel) {
        $item = $item === null ? new class{} : $item->document();
      } elseif ($item instanceof BaseEnum) {
        $item = $item->value();
      } elseif (is_array($item)) {
        foreach ($item as &$i) {
          $i = $i instanceof BaseRef || $i instanceof BaseModel ?
            $i->document() :
            $i;
        }
      }
    }

    return $document;
  }

  final public function reference() {
    return BaseRef::fromModel($this);
  }
}

class BaseRef {
  protected $__ref;
  protected $__model;
  protected $__collection;
  protected $_id;
  protected $model;

  public function __construct($model) {
    invariant(
      $model->_id,
      'Cannot create a reference from a non-existing document');

    invariant(
      $model::COLLECTION,
      'Cannot create a reference from Models with no collections');

    $this->__ref = true;
    $this->_id = $model->_id;
    $this->__model = get_class($model);
    $this->__collection = $model::COLLECTION;
  }

  public function __set($key, $value) {
    invariant_violation('Cannot set attributes on a reference');
  }

  public function __get($key) {
    // no idx() here, will trust BaseModel's __get()
    return $this->model() !== null ? $this->model->$key : null;
  }

  public static function fromModel($model) {
    return new self($model);
  }

  public function model() {
    if ($this->model) {
      return $this->model;
    }

    $store = MongoInstance::get($this->__collection);
    $doc = $store->findOne(['_id' => $this->_id]);
    if ($doc === null) {
      ls('Broken reference: %s:%s', $this->__collection, $this->_id);
      return null;
    }

    $model = $this->__model;
    $this->model = new $model($doc);
    return $this->model;
  }

  public function document() {
    return [
      '__ref' => true,
      '_id' => $this->_id,
      '__model' => $this->__model,
      '__collection' => $this->__collection,
    ];
  }
}
