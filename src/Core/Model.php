<?php
namespace Core;

use App;
use Core\Lib\Arrays;

/**
 * 基于表的模型基类
 *
 * @author lisijie <lsj86@qq.com>
 * @package Core
 */
abstract class Model extends Component
{

    /**
     * DB对象
     *
     * @var \Core\Db
     */
    protected $db;

    /**
     * 数据库配置
     *
     * @var string
     */
    protected $dbNode = 'default';

    /**
     * 表名
     * @var string
     */
    protected $table = '';

    /**
     * 主键字段名
     * @var string
     */
    protected $pk = 'id';

    /**
     * 自动生成创建时间戳的字段名
     *
     * 可以让您在调用 insert 方法插入到数据库时自动将对应的字段设置为当前时间戳，为空表示不启用。
     *
     * @var string
     */
    protected $autoCreated = '';

    /**
     * 自动生成更新时间戳的字段名
     *
     * 可以让您在调用 update 方法更新到数据库时自动将对应的字段设置为当前时间戳，为空表示不启用。
     *
     * @var string
     */
    protected $autoUpdated = '';

    private final function __construct()
    {
        $this->db = App::db($this->dbNode);
        $this->init();
    }

    /**
     * 获取模型单例
     *
     * @return static
     */
    public static function getInstance()
    {
        static $instance;
        if (!$instance) {
            $instance = new static();
        }
        return $instance;
    }

    /**
     * 子类初始化方法
     *
     * 指定DB连接，表名等操作
     */
    protected function init()
    {

    }

    /**
     * 根据主键ID查询
     *
     * @param $id
     * @return array
     */
    public final function get($id)
    {
        return $this->getRow([$this->pk => $id]);
    }

    /**
     * 表统计
     *
     * @param array $filter
     * @return int
     */
    public final function count(array $filter = [])
    {
        $sql = "SELECT COUNT(*) FROM " . $this->getTable();
        if (!empty($filter)) $sql .= " WHERE " . $this->parseFilter($filter);
        return intval($this->db->getOne($sql));
    }

    /**
     * 查询数据
     *
     * @param array $fields 查询字段
     * @param array $filter 查询条件
     * @param array $order 排序条件
     * @param int $limit 查询数量
     * @param int $offset 偏移量
     * @return array
     */
    public final function select(array $fields = [], array $filter = [], array $order = [], $limit = 0, $offset = 0)
    {
        $table = $this->getTable();
        $fields = $this->parseFields($fields);
        $sql = "SELECT {$fields} FROM {$table}";
        if (!empty($filter)) {
            $sql .= " WHERE " . $this->parseFilter($filter);
        }
        if (!empty($order)) {
            $orderSql = [];
            foreach ($order as $key => $val) {
                $orderSql[] = "{$key} " . (strtolower($val) == 'asc' ? 'ASC' : 'DESC');
            }
            $sql .= " ORDER BY " . implode(', ', $orderSql);
        }
        if ($limit > 0) $sql .= $offset > 0 ? " LIMIT $offset, $limit" : " LIMIT $limit";
        return $this->db->select($sql);
    }

    /**
     * 分页查询
     *
     * @param array $fields 查询字段
     * @param array $filter 查询条件
     * @param array $order 排序条件
     * @param int $page 页码
     * @param int $size 每页数量
     * @return array
     */
    public final function page(array $fields, array $filter, array $order, $page = 1, $size = 20)
    {
        $offset = 0;
        if ($page > 0 && $size > 0) {
            $page = max(intval($page), 1);
            $size = max(intval($size), 1);
            $offset = ($page - 1) * $size;
        }
        return $this->select($fields, $filter, $order, $size, $offset);
    }

    /**
     * 插入记录
     *
     * 可进行单条插入、批量插入、更新插入，当进行单条插入时，返回的是插入记录的自增主键ID，如果没有自增主键，则返回0。
     * 如果是批量插入，则返回包含所有插入的ID数组。
     *
     * @param array $data 插入数据
     * @param bool $replace 是否替换插入
     * @param bool $multi 是否批量插入
     * @param bool $ignore 是否忽略重复
     * @return string|array 最后插入的自增ID，批量插入的话返回所有ID
     */
    public final function insert(array $data, $replace = false, $multi = false, $ignore = false)
    {
        if (empty($data)) return false;
        $table = $this->getTable();
        if ($this->autoCreated || $this->autoUpdated) {
            $now = $this->now();
            if ($multi) {
                foreach ($data as &$item) {
                    if ($this->autoCreated && !isset($item[$this->autoCreated])) {
                        $item[$this->autoCreated] = $now;
                    }
                    if ($this->autoUpdated && !isset($item[$this->autoUpdated])) {
                        $item[$this->autoUpdated] = $now;
                    }
                }
                unset($item);
            } else {
                if ($this->autoCreated && !isset($data[$this->autoCreated])) {
                    $data[$this->autoCreated] = $now;
                }
                if ($this->autoUpdated && !isset($data[$this->autoUpdated])) {
                    $data[$this->autoUpdated] = $now;
                }
            }
        }
        if ($multi) { // 批量查询使用事务提高插入性能
            $this->db->beginTransaction();
            $result = $this->db->insert($table, $data, $replace, $multi, $ignore);
            $this->db->commit();
        } else {
            $result = $this->db->insert($table, $data, $replace, $multi, $ignore);
        }
        return $result;
    }

    /**
     * 插入或更新
     *
     * 当主键或唯一索引不存在时，进行插入，当出现主键或唯一索引冲突时，则进行更新。
     * 返回值说明：
     *   返回影响的记录数，如果是新插入的数据，则+1，如果是更新数据，则+2，如果数据没有发生变化，则为0。
     *   因此，当对单条记录进行插入或更新时，可以根据返回值判断，数据是更新还是插入，还是不变。
     *   当进行批量操作时，返回值只能表示有没数据被插入或更新，并不能表示具体插入或更新了多少行。
     *
     * @param array $data 要插入的数据
     * @param bool|false $multi 是否批量操作
     * @return int 影响记录数
     */
    public final function insertOrUpdate(array $data, $multi = false)
    {
        if (empty($data)) return 0;
        if (!$multi) $data = [$data];
        if ($this->autoCreated || $this->autoUpdated) {
            $now = $this->now();
            foreach ($data as &$item) {
                if ($this->autoCreated && !isset($item[$this->autoCreated])) {
                    $item[$this->autoCreated] = $now;
                }
                if ($this->autoUpdated && !isset($item[$this->autoUpdated])) {
                    $item[$this->autoUpdated] = $now;
                }
            }
            unset($item);
        }
        $table = $this->getTable();
        $fields = '`' . implode('`,`', array_keys($data[0])) . '`'; //字段
        // 插入值列表
        $values = [];
        foreach ($data as $row) {
            $values[] = implode(',', array_map([$this->db, 'quote'], array_values($row)));
        }
        $values = '(' . implode('),(', $values) . ')';
        // 更新列表
        foreach (array_keys($data[0]) as $field) {
            if ($this->autoCreated && $field == $this->autoCreated) {
                continue;
            }
            $updates[] = "`{$field}`=VALUES(`{$field}`)";
        }
        $updates = implode(',', $updates);

        $sql = "INSERT INTO {$table} ({$fields}) VALUES {$values} ON DUPLICATE KEY UPDATE {$updates}";
        return $this->db->execute($sql);
    }

    /**
     * 查询一行记录
     *
     * @param array $filter 过滤条件
     * @param array $fields 字段
     * @return array
     */
    public final function getRow(array $filter = [], array $fields = [])
    {
        $fields = $this->parseFields($fields);
        $sql = "SELECT {$fields} FROM " . $this->getTable();
        if (!empty($filter)) {
            $sql .= " WHERE " . $this->parseFilter($filter);
        }
        $sql .= " LIMIT 1";
        return $this->db->getRow($sql);
    }

    /**
     * 更新记录
     *
     * @param array $data 更新数据
     * @param array $filter 更新条件
     * @return int
     */
    public final function update(array $data, array $filter)
    {
        if (empty($data)) {
            return false;
        }
        if ($this->autoUpdated && !isset($data[$this->autoUpdated])) {
            $data[$this->autoUpdated] = $this->now();
        }
        $table = $this->getTable();
        $sql = "UPDATE {$table} SET ";
        $split = '';
        foreach ($data as $key => $val) {
            $sql .= "{$split}`{$key}` = :{$key}";
            $split = ', ';
        }
        if (!empty($filter)) {
            $sql .= " WHERE " . $this->parseFilter($filter);
        }
        return $this->db->update($sql, $data);
    }

    /**
     * 删除记录
     *
     * @param array $filter 条件
     * @return int 返回影响行数
     */
    public final function delete(array $filter)
    {
        $table = $this->getTable();
        $sql = "DELETE FROM {$table} ";
        if (!empty($filter)) {
            $sql .= " WHERE " . $this->parseFilter($filter);
        }

        return $this->db->delete($sql);
    }

    /**
     * 字段自增
     *
     * $this->increment(['a'=>1, 'b'=>-2], ['id'=>1])
     *
     * @param array $data 字段和值
     * @param array $filter 条件
     * @return int 影响行数
     */
    public final function increment(array $data, array $filter)
    {
        if (empty($data)) {
            return false;
        }
        $table = $this->getTable();
        $sql = "UPDATE {$table} SET ";
        foreach ($data as $key => $val) {
            $sql .= " `{$key}` = `{$key}` + " . intval($val) . ",";
        }
        if ($this->autoUpdated && !isset($data[$this->autoUpdated])) {
            $sql .= " `{$this->autoUpdated}` = '" . $this->now() . "',";
        }
        $where = $this->parseFilter($filter);
        $sql = rtrim($sql, ',');
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        return $this->db->execute($sql);
    }

    /**
     * 返回表名
     *
     * @return string 表名
     */
    public final function getTable()
    {
        return $this->db->table($this->table);
    }

    /**
     * 开启事务
     *
     * @return bool
     */
    public final function beginTransaction()
    {
        return $this->db->beginTransaction();
    }

    /**
     * 提交事务
     *
     * @return bool
     */
    public final function commitTransaction()
    {
        return $this->db->commit();
    }

    public final function __toString()
    {
        return $this->table;
    }

    /**
     * 使用指定字段重新索引数组
     *
     * @param array $data
     * @param $idx
     * @return array
     */
    public final static function index(array $data, $idx)
    {
        return Arrays::index($data, $idx);
    }

    /**
     * 将数组解析成SQL
     *
     * @param array $filter
     * @return string
     */
    protected function parseFilter(array $filter)
    {
        $where = [];
        foreach ($filter as $field => $val) {
            if (($pos = strrpos($field, '__')) > 0) {
                $op = substr($field, $pos + 2);
                $field = substr($field, 0, $pos);
                switch ($op) {
                    case 'gt': //大于
                        $where[] = "`{$field}` > " . $this->db->quote($val);
                        break;
                    case 'gte': //大于等于
                        $where[] = "`{$field}` >= " . $this->db->quote($val);
                        break;
                    case 'lt': //小于
                        $where[] = "`{$field}` < " . $this->db->quote($val);
                        break;
                    case 'lte': //小于等于
                        $where[] = "`{$field}` <= " . $this->db->quote($val);
                        break;
                    case 'ne': //不等于
                        $where[] = "`{$field}` != " . $this->db->quote($val);
                        break;
                    case 'like': //LIKE ‘%%’, 支持多个
                        if (!is_array($val)) {
                            $val = [$val];
                        }
                        foreach ($val as $k => $v) {
                            $v = $this->escapeLike($v);
                            $where[] = "`{$field}` LIKE '%{$v}%'";
                        }
                        break;
                    case 'notlike': //NOT LIKE ‘%%’, 支持多个
                        if (!is_array($val)) {
                            $val = [$val];
                        }
                        foreach ($val as $k => $v) {
                            $v = $this->escapeLike($v);
                            $where[] = "`{$field}` NOT LIKE '%{$v}%'";
                        }
                        break;
                    case 'startswith': //LIKE 'xxx%'
                        $val = $this->escapeLike($val);
                        $where[] = "`{$field}` LIKE '{$val}%'";
                        break;
                    case 'endswith': //LIKE '%xxx'
                        $val = $this->escapeLike($val);
                        $where[] = "`{$field}` LIKE '%{$val}'";
                        break;
                    case 'between': //between 'a' AND 'b'
                        $where[] = "(`{$field}` BETWEEN " . $this->db->quote($val[0]) . " AND " . $this->db->quote($val[1]) . ")";
                        break;
                    case 'in': // IN (1,2,3)
                        if (!is_array($val)) $val = [$val];
                        foreach ($val as $k => $v) {
                            $val[$k] = $this->db->quote($v);
                        }
                        $where[] = "`{$field}` IN (" . implode(',', $val) . ")";
                        break;
                    case 'notin': // NOT IN (1,2,3)
                        if (!is_array($val)) $val = [$val];
                        foreach ($val as $k => $v) {
                            $val[$k] = $this->db->quote($v);
                        }
                        $where[] = "`{$field}` NOT IN (" . implode(',', $val) . ")";
                        break;
                    case 'isnull':
                        if ($val) {
                            $where[] = "`{$field}` IS NULL";
                        } else {
                            $where[] = "`{$field}` IS NOT NULL";
                        }
                        break;
                }
            } elseif (is_array($val)) {
                foreach ($val as $k => $v) {
                    $val[$k] = $this->db->quote($v);
                }
                $where[] = "`{$field}` IN (" . implode(',', $val) . ")";
            } else {
                $where[] = "`{$field}` = " . $this->db->quote($val);
            }
        }
        return implode(' AND ', $where);
    }

    /**
     * like转义
     *
     * @param $string
     * @return string
     */
    private function escapeLike($string)
    {
        if (empty($string)) {
            return $string;
        }
        $string = strtr($string, ['\\' => '\\\\']);
        return strtr(substr($this->db->quote($string), 1, -1), ['%' => '\%', '_' => '\_']);
    }

    /**
     * 字段解析成SQL
     *
     * @param array $fields
     * @return string
     */
    protected function parseFields(array $fields)
    {
        if (empty($fields)) {
            return '*';
        }
        $result = [];
        foreach ($fields as $key => $val) {
            if (is_string($key)) {
                $result[] = "`{$key}` AS {$val}";
            } else {
                $result[] = "`{$val}`";
            }
        }
        return implode(', ', $result);
    }

    /**
     * 返回当前时间
     *
     * 用于 autoCreated、autoUpdated 的时间函数，如果字段类型不是 int，而是 datetime 之类的，可以覆盖本函数以返回对应的值。
     *
     * @return int
     */
    protected function now()
    {
        return time();
    }
}
