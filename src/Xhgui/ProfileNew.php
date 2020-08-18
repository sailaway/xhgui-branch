<?php
/**
 * Domain object for handling profile runs.
 *
 * Provides method to manipulate the data from a single profile run.
 */
class Xhgui_NewProfile extends Xhgui_Profile
{
    protected $_keys = array('ct', 'wt', 'cpu', 'mu', 'pmu');
    protected $_exclusiveKeys = array('ewt', 'ecpu', 'emu', 'epmu');

    private function mergeClsFunction($data){
        if (isset($data['cls'])){
            $data['function'] = $data['cls'] . "::" . $data['function'];
        }
        if (is_array($data['children'])){
            for ($i = 0;$i < count($data['children']);$i++){
                $data['children'][$i] = $this->mergeClsFunction($data['children'][$i]);
            }
        }
        return $data;
    }

    public function __construct($data,$convert = true)
    {
        // 把profile 里面 cls 合并到 function
        if (!empty($data['profile'])) {
            $data['profile'] = $this->mergeClsFunction($data['profile']);
        }

        parent::__construct($data,$convert);
    }

    private function processNode($parentNode,$node,&$result){
        $func   = $node['function'];
        $parent = $parentNode['function'];

        $values = $this->_sumKeys([],$node);

        // Generate collapsed data.
        if (isset($result[$func])) {
            $result[$func] = $this->_sumKeys($result[$func], $values);
            $result[$func]['parents'][] = $parent;
        } else {
            $result[$func] = $values;
            $result[$func]['parents'] = array($parent);
        }

        // Build the indexed data.
        if ($parent === null) {
            $parent = self::NO_PARENT;
        }
        if (!isset($this->_indexed[$parent])) {
            $this->_indexed[$parent] = array();
        }
        if(isset($this->_indexed[$parent][$func])){
            $this->_indexed[$parent][$func] = $this->_sumKeys($this->_indexed[$parent][$func], $values);
        } else {
            $this->_indexed[$parent][$func] = $values;
        }

        if (is_array($node['children'])){
            foreach ($node['children'] as $child) {
                $this->processNode($node,$child,$result);
            }
        }
    }

    /**
     * Convert the raw data into a flatter list that is easier to use.
     *
     * This removes some of the parentage detail as all calls of a given
     * method are aggregated. We are not able to maintain a full tree structure
     * in any case, as xhprof only keeps one level of detail.
     *
     * _process 通过 _sumKeys 统计的是同一个函数在整体请求下的总的时间累加
     * 并不能体现函数调用包含关系下的时间累加，因此体现在火焰图中的时候是不正确的
     * @return void
     */
    protected function _process()
    {
        $result = array();
        $this->processNode(null,$this->_data['profile'],$result);
        $this->_collapsed = $result;
    }

    /**
     * Return a structured array suitable for generating callgraph visualizations.
     *
     * Functions whose inclusive time is less than 2% of the total time will
     * be excluded from the callgraph data.
     *
     * @return array
     */
    public function getCallgraph($metric = 'wt', $threshold = 0.01)
    {
        $valid = array_merge($this->_keys, $this->_exclusiveKeys);
        if (!in_array($metric, $valid)) {
            throw new Exception("Unknown metric '$metric'. Cannot generate callgraph.");
        }
        $node = $this->_data['profile'];
        $mainVal = floatval($node[$metric]);

        $this->_callNodes = $this->_callLinks = array();
        $this->_visitedNodes = array();
        $this->calculateCallGraph($node, $mainVal, $metric, $threshold,true);
        $out = array(
            'metric' => $metric,
            'total' => $mainVal,
            'nodes' => $this->_callNodes,
            'links' => $this->_callLinks
        );
        unset($this->_callNodes, $this->_callLinks,$this->_visitedNodes);
        return $out;
    }

    protected function calculateCallGraph($node, $mainVal, $metric, $threshold,$subtractChildrenVal){
        if (!in_array($node['function'],$this->_visitedNodes)){
            $value = floatval($node[$metric]);
            if ($subtractChildrenVal && !empty($node['children'])){
                foreach ($node['children'] as $child){
                    $value -= floatval($child[$metric]);
                }
            }
            $this->_callNodes[] = [
                'name'      => $node['function'],
                'callCount' => $node['ct'],
                'value'     => $value,
            ];
            $this->_visitedNodes[] = $node['function'];
        }

        if (empty($node['children'])){
            return;
        }
        foreach ($node['children'] as $child){
            if (floatval($child[$metric]) / $mainVal <= $threshold) {
                continue;
            }
            $this->_callLinks[] = [
                'source'    => $node['function'],
                'target'    => $child['function'],
                'callCount' => $child['ct'],
            ];
            $this->calculateCallGraph($child,$mainVal, $metric, $threshold,$subtractChildrenVal);
        }
    }

    /**
     * Return a structured array suitable for generating flamegraph visualizations.
     *
     * Functions whose inclusive time is less than 1% of the total time will
     * be excluded from the callgraph data.
     *
     * @return array
     */
    public function getFlamegraph($metric = 'wt', $threshold = 0.01)
    {
        $valid = array_merge($this->_keys, $this->_exclusiveKeys);
        if (!in_array($metric, $valid)) {
            throw new \Exception("Unknown metric '$metric'. Cannot generate flamegraph.");
        }
        $node = $this->_data['profile'];
        $mainVal = floatval($node[$metric]);
        $result = $this->getFlameDataByCallNode($node,$metric,$mainVal,$threshold,true);

        return array('data' => $result, 'sort' => $this->_visited);
    }

    protected function getFlameDataByCallNode($node,$metric,$mainVal,$threshold,$subtractChildrenVal){
        $value = $node[$metric];
        if ($subtractChildrenVal && !empty($node['children'])){
            foreach ($node['children'] as $child){
                $value -= floatval($child[$metric]);
            }
        }
        $data = [
            'name' => $node['function'],
            'value' => $value
        ];
        if (!empty($node['children'])){
            $children = [];
            foreach ($node['children'] as $child){
                if (floatval($child[$metric]) / $mainVal <= $threshold) {
                    continue;
                }
                $children[] = $this->getFlameDataByCallNode($child,$metric,$mainVal,$threshold,$subtractChildrenVal);
            }
            if (!empty($children)){
                $data['children'] = $children;
            }
        }
        return $data;
    }
    
}
