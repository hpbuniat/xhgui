<?php
/**
 * Domain object for handling profile runs.
 *
 * Provides method to manipulate the data from a single profile run.
 */
class Xhgui_Profile
{
    protected $_data;

    protected $_keys = array('ct', 'wt', 'cpu', 'mu', 'pmu');

    public function __construct($profile, $convert = true)
    {
        $this->_data = $profile;
        if (!empty($profile['profile']) && $convert) {
            $this->_process();
        }
    }

    /**
     * Convert the raw data into a flatter list that is easier to use.
     *
     * This removes some of the parentage detail as all calls of a given
     * method are aggregated. We are not able to maintain a full tree structure
     * in any case, as xhprof only keeps one level of detail.
     *
     * @return void
     */
    protected function _process()
    {
        $this->_data['original'] = $this->_data['profile'];

        $result = array();
        foreach ($this->_data['profile'] as $name => $values) {
            list($parent, $func) = $this->splitName($name);
            if (isset($result[$func])) {
                $result[$func] = $this->_sumKeys($result[$func], $values);
                $result[$func]['parents'][] = $parent;
            } else {
                $result[$func] = $values;
                $result[$func]['parents'] = array($parent);
            }
        }
        $this->_data['profile'] = $result;
    }

    /**
     * Sum up the values in $this->_keys;
     *
     * @param array $a The first set of profile data
     * @param array $b The second set of profile data.
     * @return array Merged profile data.
     */
    protected function _sumKeys($a, $b)
    {
        foreach ($this->_keys as $key) {
            $a[$key] += $b[$key];
        }
        return $a;
    }

    /**
     * Get the profile run data.
     *
     * TODO remove this and move all the features using it into this/
     * other classes.
     *
     * @return array
     */
    public function getProfile()
    {
        return $this->_data['profile'];
    }

    public function getId()
    {
        return $this->_data['_id'];
    }

    /**
     * Get meta data about the profile. Read's a . split path
     * out of the meta data in a profile. For example `SERVER.REQUEST_TIME`
     *
     * @param string $key The dotted key to read.
     * @return null|mixed Null on failure, otherwise the stored value.
     */
    public function getMeta($key = null)
    {
        $data = $this->_data['meta'];
        if ($key === null) {
            return $data;
        }
        $parts = explode('.', $key);
        foreach ($parts as $key) {
            if (is_array($data) && isset($data[$key])) {
                $data =& $data[$key];
            } else {
                return null;
            }
        }
        return $data;
    }

    /**
     * Read data from the profile run.
     *
     * @param string $key The function key name to read.
     * @param string $metric The metric to read.
     * @return null|float
     */
    public function get($key, $metric = null)
    {
        if (!isset($this->_data['profile'][$key])) {
            return null;
        }
        if (empty($metric)) {
            return $this->_data['profile'][$key];
        }
        if (!isset($this->_data['profile'][$key][$metric])) {
            return null;
        }
        return $this->_data['profile'][$key][$metric];
    }

    /**
     * Find a function matching a watched function.
     *
     * @param string $pattern The pattern to look for.
     * @return null|array An list of matching functions
     *    or null.
     */
    public function getWatched($pattern)
    {
        if (isset($this->_data['profile'][$pattern])) {
            $data = $this->_data['profile'][$pattern];
            $data['function'] = $pattern;
            return array($data);
        }
        $matches = array();
        $keys = array_keys($this->_data['profile']);
        foreach ($keys as $func) {
            if (preg_match('/^' . $pattern . '$/', $func)) {
                $data = $this->_data['profile'][$func];
                $data['function'] = $func;
                $matches[] = $data;
            }
        }
        return $matches;
    }

    /**
     * Find the parent and children method/functions for a given
     * symbol.
     *
     * The parent/children arrays will contain all the callers + callees
     * of the symbol given. The current index will give the total
     * inclusive values for all properties.
     *
     * @param array $profile Array of profile data.
     * @param string $symbol The name of the function/method to find
     *    relatives for.
     * @return array List of (parent, current, children)
     */
    public function getRelatives($symbol)
    {
        $parents = array();

        // If the function doesn't exist, it won't have parents/children
        if (empty($this->_data['profile'][$symbol])) {
            return array(
                array(),
                array(),
                array(),
            );
        }
        $current = $this->_data['profile'][$symbol];
        $current['function'] = $symbol;

        // Use the parents data to collect parents.
        $parentMethods = $current['parents'];
        foreach ($parentMethods as $parent) {
            if (isset($this->_data['profile'][$parent])) {
                $parents[] = array('function' => $parent) + $this->_data['profile'][$parent];
            }
        }

        $children = $this->_getChildren($symbol);
        return array($parents, $current, $children);
    }

    /**
     * Find symbols that are the children of the given name.
     *
     * @param string $symbol The name of the function to find children of.
     * @return array An array of child methods.
     */
    protected function _getChildren($symbol) {
        $children = array();

        // Find children with linear search.
        $childName = $symbol . '==>';
        foreach ($this->_data['original'] as $name => $data) {
            if (strpos($name, $childName) === 0) {
                $nameParts = $this->splitName($name);
                $children[] = $data + array('function' => $nameParts[1]);
            }
        }

        return $children;
    }

    /**
     * Extracts a single dimension of data
     * from a profile run.
     *
     * Useful for creating bar/column graphs.
     * The profile data will be sorted by the column
     * and then the $limit records will be extracted.
     *
     * @param string $dimension The dimension to extract
     * @param int $limit Number of elements to pull
     * @return array Array of data with name = function name and 
     *   value = the dimension.
     */
    public function extractDimension($dimension, $limit)
    {
        $profile = $this->sort($dimension, $this->_data['profile']);
        $slice = array_slice($profile, 0, $limit);
        $extract = array();
        foreach ($slice as $func => $funcData) {
            $extract[] = array(
                'name' => $func,
                'value' => $funcData[$dimension]
            );
        }
        return $extract;
    }

    /**
     * Generate the approximate exclusive values for each metric.
     *
     * We get a==>b as the name, we need a key for a and b in the array
     * to get exclusive values for A we need to subtract the values of B (and any other children);
     * call passing in the entire profile only, should return an array of
     * functions with their regular timing, and exclusive numbers inside ['exclusive']
     *
     * Consider:
     *              /---c---d---e
     *          a -/----b---d---e
     *
     * We have c==>d and b==>d, and in both instances d invokes e, yet we will
     * have but a single d==>e result. This is a known and documented limitation of XHProf
     *
     * We have one d==>e entry, with some values, including ct=2
     * We also have c==>d and b==>d
     *
     * We should determine how many ==>d options there are, and equally
     * split the cost of d==>e across them since d==>e represents the sum total of all calls.
     *
     * Notes:
     *  Function names are not unique, but we're merging them
     *
     * @return Xhgui_Profile A new instance with exclusive data set.
     */
    public function calculateExclusive()
    {
        // Init exclusive values
        foreach ($this->_data['profile'] as &$data) {
            $data['ewt'] = $data['wt'];
            $data['emu'] = $data['mu'];
            $data['ecpu'] = $data['cpu'];
            $data['ect'] = $data['ct'];
            $data['epmu'] = $data['pmu'];
        }
        unset($data);
        $exclusiveKeys = array('ewt', 'emu', 'ecpu', 'ect', 'epmu');

        // Go over each method and remove each childs metrics
        // from the parent.
        foreach ($this->_data['profile'] as $name => $data) {
            // TODO this is super slow, optimize this.
            $children = $this->_getChildren($name);
            foreach ($children as $child) {
                $this->_data['profile'][$name]['ewt'] -= $child['wt'];
                $this->_data['profile'][$name]['emu'] -= $child['mu'];
                $this->_data['profile'][$name]['ecpu'] -= $child['cpu'];
                $this->_data['profile'][$name]['ect'] -= $child['ct'];
                $this->_data['profile'][$name]['epmu'] -= $child['pmu'];
            }
        }
        return $this;
    }

    /**
     * Sort data by a dimension.
     *
     * @param string $dimension The dimension to sort by.
     * @param array $data The data to sort.
     * @return array The sorted data.
     */
    public function sort($dimension, $data)
    {
        $sorter = function ($a, $b) use ($dimension) {
            if ($a[$dimension] == $b[$dimension]) {
                return 0;
            }
            return $a[$dimension] > $b[$dimension] ? -1 : 1;
        };
        uasort($data, $sorter);
        return $data;
    }

    /**
     * Split a key name into the parent==>child format.
     *
     * @param string $name The name to split.
     * @return array An array of parent, child. parent will be null if there
     *    is no parent.
     */
    public function splitName($name)
    {
        $a = explode("==>", $name);
        if (isset($a[1])) {
            return $a;
        }
        return array(null, $a[0]);
    }

}
