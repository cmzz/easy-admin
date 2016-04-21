<?php
namespace Home\Controller;

/**
 * EmptyController
 * 空控制器
 */
class EmptyController extends CommonController {
    /**
     * 需要拆分列表值的表单域类型
     * @var array
     */
    public $types = array('checkbox', 'select', 'radio');

    /**
     * 执行过滤
     * @return
     */
    public function _initialize() {
        parent::_initialize();
        $this->ensureExistContoller();
    }

    /**
     * 模型数据列表首页
     * @return
     */
    public function index() {
        // 得到数据表名称
        $tblName = D('Model', 'Service')->getTblName($this->getCtrName());
        $model = M('Model')->getByTblName($tblName);
        if (!$model) {
            return $this->error('系统出现错误了！');
        }

        // 查询主键
        $pk = $this->getPrimaryKey();

        //组装排序
        $order_by_str = '';
        foreach ($pk as $v_pk){
            $order_by_str .=' '.$v_pk.' DESC,';
        }
        $order_by_str= substr($order_by_str, 0, strlen($order_by_str)-1);

        // 得到模型对应的字段
        $where = array(
            'model_id' => $model['id'],
            'is_list_show' => 1
        );
        $fields = D('Field')->relation(true)->where($where)->select();

        // 排序
        $u_order = '';
        $search_opt = [];
        foreach($fields as $val) {
            # 搜索字段
            if($val['is_list_show'] || $val['is_search']) {
                $search_opt[] = [$val['name'], $val['comment']];
            }

            if($val['order_by'] == 1) {
                $u_order .=' '.$val['name'].' '.$val['sort'].',';
            }
        }
        if($u_order) {
            $u_order= substr( $u_order, 0, strlen($u_order) -1 );
            $order_by_str = $u_order;
        }

        if(!$model['search_num'])
            $model['search_num'] = 2;

        if($search_opt && $model['search_num'] > 0) {
            $search_html = $this->gen_search($search_opt, $model['search_num']);
        }

        $where = $this->gen_where($model['id']);

        // 得到分页数据
        $result = $this->getPagination('Default', $where, null, $order_by_str);

        $rows = array_map("strip_sql_injection", $result['data']);
        unset($result['data']);

        // 处理需要替换的字段值
        foreach ($fields as $field) {
            $fn = $field['name'];

            //时间戳转换
            if ($field['input']['type']=='date_utime'){
                foreach ($rows as $key => $row) {
                    $rows[$key][$fn] = date('Y-m-d H:i:s', $row[$fn]);
                }
            }
            //时间戳转换
            if ($field['input']['type']=='date_microtime'){
                foreach ($rows as $key => $row) {
                    $row[$fn] = floor(($row[$fn]/1000));
                    $rows[$key][$fn] = date('Y-m-d H:i:s', $row[$fn]);
                }
            }

            // created_at、updated_at换成日期格式
            if (($field['is_system'] && $field['is_list_show'])
                && ('created_at' == $fn || 'updated_at' == $fn)) {
                foreach ($rows as $key => $row) {
                    $rows[$key][$fn] = date('Y-m-d H:i:s', $row[$fn]);
                }
            }

            // checkbox，radio，select类型
            if (in_array($field['input']['type'], $this->types)
                && !empty($field['input']['opt_value'])) {
                $opts = D('Input', 'Logic')
                         ->optValueToArray($field['input']['opt_value']);
                $opts = array_flip($opts['opt_value']);

                foreach ($rows as $key => $row) {
                    if ('checkbox' == $field['input']['type']) {
                        $value = '';
                        $values = explode(',', $row[$fn]);
                        foreach ($values as $val) {
                            $value .= "{$opts[$val]}<br/>";
                        }
                        $rows[$key][$fn] = $value;
                    } else {
                        $rows[$key][$fn] = $opts[$row[$fn]];
                    }
                }
            }

            // 关联表类型
            if (0 != $field['relation_model']
                && !empty($field['relation_value'])
                && !empty($field['relation_field'])) {

                // 被关联的模型
                $rModel = M('Model')->getById($field['relation_model']);

                // 表模型名
                $mn = D('Model', 'Service')->getCtrlName($rModel['tbl_name']);
                $model_mn = $this->getModel($mn);

                foreach ($rows as $key => $row) {
                    $tmp = "{$field['relation_field']}={$row[$fn]}";

                    $rField = $model_mn->where($tmp)
                                    ->field("{$field['relation_value']}")
                                    ->find();
                    $rows[$key][$fn] = $rField[$field['relation_value']];
                }
            }
        }

        $this->assign('search_html', $search_html);
        $this->assign('pk', $pk);
        $this->assign('model', $model);
        $this->assign('fields', $fields);
        $this->assign('rows', $rows);
        $this->assign('rows_count', $result['total_rows']);
        $this->assign('page', $result['show']);
        $this->display('Default/index');
    }

    /**
     * 添加模型数据
     * @return
     */
    public function add() {
        $tblName = D('Model', 'Service')->getTblName($this->getCtrName());
        $inputs = D('Input', 'Service')->getAddInputsByTblName($tblName);

        $this->assign('inputs', $inputs);
        $this->display('Default/add');
    }

    /**
     * 创建模型数据
     * @return
     */
    public function create() {
        // 得先得到这个模型的所有字段
        $fields = D('Field', 'Service')->getByCtrlName($this->getCtrName());
        $defaultService = D('Default', 'Service');

        // 创建数据
        $data = $_POST[str_replace('.', '_', strtolower($this->getCtrName()))];
        $result = $defaultService->create($data, $fields, $this->getCtrName());

        if (!$result['status']) {
            return $this->errorReturn($result['data']['error']);
        }

        // 插入数据
        $result = $defaultService->add($result['data'], $this->getCtrName());
        if (!$result['status']) {
            return $this->errorReturn('添加数据失败！');
        }

        return $this->successReturn('成功添加数据！',
                                    U($this->getCtrName() . '/index'));
    }

    /**
     * 编辑模型数据
     * @return
     */
    public function edit() {
        $m = $this->getModel();
        $data = $m->where("id={$_GET['id']}")->find();

        if (is_null($data)) {
            return $this->error('需要编辑的数据不存在！');
        }

        $tblNmae = D('Model', 'Service')->getTblName($this->getCtrName());
        $inputs = D('Input','Service')->getEditInputsByTblName($tblNmae,$data);
        $hidden = array(
            'name' => strtolower($this->getCtrName()) . '[id]',
            'value' => $_GET['id']
        );

        $this->assign('hidden', $hidden);
        $this->assign('inputs', $inputs);
        $this->display('Default/edit');
    }

    /**
     * 更新模型数据
     */
    public function update() {
        $ctrName = $this->getCtrName();
        $m = $this->getModel();
        $data = $_POST[str_replace('.', '_', $ctrName)];

        if (!isset($data['id']) || is_null($m->getById($data['id']))) {
            return $this->errorReturn('无效的操作！');
        }

        $defaultService = D('Default', 'Service');
        $fields = D('Field', 'Service')->getByCtrlName($ctrName);

        // 创建数据
        $result = $defaultService->create($data,
            $fields,
            $ctrName,
            'update');
        if (!$result['status']) {
            return $this->errorReturn($result['data']['error']);
        }

        // 更新数据
        $result = $defaultService->update($result['data'], $ctrName);
        if (!$result['status']) {
            return $this->errorReturn('更新数据失败！');
        }

        return $this->successReturn('更新数据成功！',
                                    U($this->getCtrName() . '/index'));
    }

    /**
     * 删除模型数据
     * @return
     */
    public function delete() {
        $m = $this->getModel();

        if (!isset($_GET['id'])
            || is_null($m->getById($_GET['id']))) {
            return $this->errorReturn('需要删除的数据不存在！');
        }

        $result = D('Default', 'Service')->delete($_GET['id'], $this->getCtrName());
        if (!$result['status']) {
            return $this->errorReturn('删除数据失败！');
        }

        return $this->successReturn('成功删除数据！');
    }

    /**
     * 空操作
     * @return
     */
    public function _empty() {
        return $this->error('亲，您访问的页面不存在！');
    }

    /**
     * 确保控制器对应的菜单存在
     * @return
     */
    protected function ensureExistContoller() {
    	$menu = fast_cache('model_menu', '', APP_PATH . '/Common/Conf/');

        $ctrName = $this->getCtrName();
        if (!array_key_exists($ctrName, $menu)) {
            return $this->_empty();
        }
    }

    protected function getModel($tblName="") {
        if (!$tblName) {
            $tblName = $this->getCtrName();
        }

        if(strpos($tblName, '.') !== false) {
            return M($tblName, null);
        } else {
            return M($tblName);
        }
    }

    /**
     * 查询主键-字符串
     * @return
     */
    public function getPrimaryKeyStr() {
        $pks = $this->getPrimaryKey();
        return implode(',', $pks);
    }

    /**
     * 查询主键-列表
     * @param $tblName
     * @return array
     */
    public function getPrimaryKey($tblName) {
        // 得到数据表名称
        $ctrName = $this->getCtrName();
        if(!$tblName){
            $tblName = D('Model', 'Service')->getTblName($ctrName);
        }

        $model = M('Model')->getByTblName($tblName);
        if (!$model) {
            return $this->error('系统出现错误了！');
        }

        $pk = [];
        if(strpos($tblName,".") !== false){
            $tn_ary = explode('.', $tblName);
            $db = $tn_ary[0];
            $tableName = $tn_ary[1];
        } else {
            $db = C('DB_NAME');
            $tableName = $tblName;
        }

        $sql ="select COLUMN_NAME from INFORMATION_SCHEMA.COLUMNS where table_name='{$tableName}' and TABLE_SCHEMA='{$db}' and COLUMN_KEY='PRI'";
        $result = D('Field')->query($sql);
        if($result){
            foreach ($result as $v){
                $c_pk[]=$v['COLUMN_NAME'];
            }
            $pk = $c_pk;
        }else{
            $result = D('Field')->query("show columns from $db.$tableName");
            $pk = array($result[0]['Field']);
        }

        return $pk;
    }

    public function medit() {
        if(IS_POST) {
            $t = I('post.edit_type');
            $pk = I('post.pk');
            $ids = implode('\',\'', I('post.'.$pk));

            if(count(I('post.'.$pk)) > 1) {
                $map = " $pk in ('".$ids."') ";
            } else {
                $map = " $pk = '".implode('',I('post.'.$pk))."' ";
            }

            $ret = false;
            $m = $this->getModel();
            switch($t) {
                case "delete":
                    $ret = $m->where($map)->delete();
                    break;
            }

            if($ret !== false) {
                $this->successReturn("操作成功!");
            } else {
                $this->errorReturn("操作失败!");
            }
        }
    }

    public function gen_search($search_opt, $num) {
        $condition_arr = [
            ['%', '包含'],
            ['=', '等于'],
            ['!=', '不等于'],
            ['!%', '不包含'],
            ['^%', '以开头'],
            ['$%', '以结尾'],
            ['\>=', '大于等于'],
            ['\>', '大于'],
            ['\<=', '小于等于'],
            ['\<', '小于'],
            ['in', 'IN'],
            ['notin', 'NOT IN'],
            ['between', 'BETWEEN '],
            ['notbetween', 'NOT BETWEEN '],
        ];

        $join_arr = [
            ['and', "并且"],
//            ['or', "或"],
        ];

        $search_html = '';
        for($i = 1; $i<= $num; $i++) {
            $search_opt_html = "";

            foreach($search_opt as $v) {
                $active = ($v[0] == $_GET['search'][$i-1]) ? "selected" : "";
                $search_opt_html .= '<option value="'.$v[0].'" '.$active.'>'.$v[1].'</option>';
            }
            $search_field_html = '<select name="search[]">'.$search_opt_html.'</select>';

            $condition_opt = "";
            foreach($condition_arr as $c) {
                $active = ($c[0] == $_GET['s_condition'][$i-1]) ? "selected" : "";
                $condition_opt .= '<option value="'.$c[0].'" '.$active.'>'.$c[1].'</option>';
            }
            $conditon_html = '<select name="s_condition[]">'.$condition_opt.'</select>';

            $join_opt = "";
            foreach($join_arr as $j) {
                $active = ($j[0] == $_GET['s_join'][$i-1]) ? "selected" : "";
                $join_opt .= '<option value="'.$j[0].'" '.$active.'>'.$j[1].'</option>';
            }
            $concat_html = '&nbsp;&nbsp;&nbsp;<select name="s_join[]">'.$join_opt.'</select>&nbsp;&nbsp;&nbsp;';

            $value_fiedl_html = '<input name="s_val[]" class="input" value="'.$_GET['s_val'][$i-1].'" style="width:100px" />';

            $search_html .= $search_field_html.$conditon_html.$value_fiedl_html;
            if($i < $num)
                $search_html .= $concat_html;
        }

        return $search_html;
    }

    public function gen_where($model_id) {
        if(!$_GET['search'])
            return ;

        $map = [];
        for($i=0; $i < count($_GET['search']); $i++) {
            $conditon = str_replace('\\', "", $_GET['s_condition'][$i]);
            $name = $_GET['search'][$i];
            $value = urldecode($_GET['s_val'][$i]);

            $fie_where = array(
                'model_id' => $model_id,
                'name' => $name
            );

            if($value === "")
                continue;

            $fields_find = D('Field')->relation(true)->where($fie_where)->select();
            $field_find = $fields_find[0];
            $type = $fields_find[0]['input']['type'];
            $opt_value = $fields_find[0]['input']['opt_value'];

            if($type == "relation_select") {
                $rv = $field_find['relation_value'];
                $rf = $field_find['relation_field'];
                $rm = $field_find['relation_model'];

                // 得到需要关联的模型
                $rm = M('Model')->field('tbl_name')->getById($rm);
                if (empty($rm)) {
                    return '';
                }
                if(strpos($rm['tbl_name'],".")){
                    $tblName = $rm['tbl_name'];
                }else{
                    // 得到不带前缀的表名
                    $tblName = substr($rm['tbl_name'], strlen(C('DB_PREFIX')));
                }

                $conditon_model = "eq";
                $this->gen_condition($conditon, $conditon_model, $value);
                $where[$rv] = [$conditon_model, $value];

                $list = [];
                if(!is_numeric($value))
                    $list = M($tblName)->where($where)->field($rf)->select();

                if($list) {
                    $r_val = [];
                    foreach($list as $relation_val) {
                        $r_val[] = $relation_val[$rf];
                    }
                    $map[$name] = ['in', $r_val];
                } else {
                    $map[$name] = [$conditon_model, $value];
                }

                continue;
            } else if($type=='radio'|| $type=='select' || $type=='checkbox'){
                $opt_value = str_replace("default","",$opt_value);
                $a = explode(':', $opt_value);
                foreach ($a as $k=>$v){
                    if($k%2==0){
                        continue;
                    }else{
                        if(strpos($v,$value)!==false){
                            $in_ary .=trim($a[$k-1]).',';
                        }
                        $new_ary[$v] = trim($a[$k-1]);
                    }
                }
                $in_ary = substr($in_ary, 0,-1);
                if(substr($value,0,1)=='='){
                    $value = str_replace('=', '', $value);
                    $where[$name] = $new_ary[$value];
                }else if($type=='checkbox'){
                    $new_ary[$value] = trim($new_ary[$value]);
                    $map[$name] = array('like','%'.$new_ary[$value].'%');
                }else{
                    $map[$name] = array('in',$in_ary);
                }

                continue;
            } else if($type=='date_utime' || $type =='date_microtime'){
                $t_list = explode("-", $value);
                $num = count($t_list);
                switch ($num){
                    case 1:
                        $stime = strtotime($t_list[0].'-01-01 00:00:00');
                        $etime =  strtotime(($t_list[0]+1).'-01-01 00:00:00');
                        break;
                    case 2:
                        $stime = strtotime($t_list[0].'-'.$t_list[1].'-01 00:00:00');
                        $etime = $stime+(86400*30);
                        break;
                    case 3:
                        $stime = strtotime($t_list[0].'-'.$t_list[1].'-'.$t_list[2].' 00:00:00');
                        $etime = $stime+86400;
                        break;
                    case 4:
                        $stime = strtotime($t_list[0].'-'.$t_list[1].'-'.$t_list[2].' '.$t_list[3].':00:00');
                        $etime = $stime+3600;
                        break;
                    case 5:
                        $stime = strtotime($t_list[0].'-'.$t_list[1].'-'.$t_list[2].' '.$t_list[3].':'.$t_list[4].':00');
                        $etime = $stime+60;
                        break;
                }

                if($type =='date_microtime'){
                    $stime = $stime*1000;
                    $etime = $etime*1000;
                }

                $map[$name]  = array('between',array($stime, $etime));

                continue;
            }

            $conditon_model = "eq";
            $this->gen_condition($conditon, $conditon_model, $value);
            $map[$name] = [$conditon_model, $value];
        }

        return $map;
    }

    protected function gen_condition($conditon, &$conditon_model, &$value) {
        switch($conditon) {
            case "=":
                $conditon_model = "eq";
                break;

            case "!=":
                $conditon_model = "neq";
                break;

            case "!%":
                $conditon_model = "exp";
                $value = ' not like "%'.$value.'%"';
                break;

            case "%":
                $conditon_model = "like";
                $value = "%".$value."%";
                break;

            case "^%":
                $conditon_model = "like";
                $value = $value."%";
                break;

            case "$%":
                $conditon_model = "like";
                $value = "%".$value;
                break;

            case ">=":
                $conditon_model = "egt";
                break;

            case ">":
                $conditon_model = "gt";
                break;

            case "<=":
                $conditon_model = "elt";
                break;

            case "<":
                $conditon_model = "lt";
                break;

            case "notin":
                $conditon_model = "not in";
                break;

            case "in":
                $conditon_model = "in";
                break;

            case "between":
                $conditon_model = "between";
                break;

            case "notbetween":
                $conditon_model = "between";
                break;
        }
    }
}
