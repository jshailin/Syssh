<?php
class People_model extends SS_Model{
	
	/**
	 * 当前编辑的“人”对象的id
	 */
	var $id;
	
	var $table='people';
	
	/**
	 * people表下的字段及其显示名
	 */
	var $fields=array(
		'character'=>'性质',
		'name'=>'名称',
		'name_en'=>'英文名',
		'abbreviation'=>'简称',
		'type'=>'分类',
		'gender'=>'性别',
		'id_card'=>'身份证号',
		'work_for'=>'工作单位',
		'position'=>'职位',
		'birthday'=>'生日',
		'city'=>'城市',
		'race'=>'民族',
		'staff'=>'首要关联职员',
		'source'=>'来源',
		'comment'=>'备注'
	);
	
	function __construct() {
		parent::__construct();
	}

	/**
	 * 根据部分人员名称返回匹配的id、名称和；类别列表
	 * @param $part_of_name
	 * @return array
	 */
	function match($part_of_name){
		$query="
			SELECT people.id,people.name,people.type
			FROM people
			WHERE people.company={$this->company->id} AND people.display=1 
				AND (name LIKE '%$part_of_name%' OR abbreviation LIKE '$part_of_name' OR name_en LIKE '%$part_of_name%')
			ORDER BY people.id DESC
		";
		
		return $this->db->query($query)->result_array();
	}

	function add(array $data=array()){
		$people=array_intersect_key($data,$this->fields);
		$people+=uidTime(true,true);
		$people['display']=1;
		
		$this->db->insert('people',$people);
		
		$new_people_id=$this->db->insert_id();
		
		if(isset($data['profiles'])){
			foreach($data['profiles'] as $name => $value){
				$this->addProfile($new_people_id,$name,$value);
			}
		}
		
		if(isset($data['labels'])){
			foreach($data['labels'] as $type => $name){
				$this->addLabel($new_people_id,$name,$type);
			}
		}
		
		return $new_people_id;
	}
	
	function update($people,$data){
		$people=intval($people);
		
		if(is_null($data)){
			return true;
		}
		
		$people_data=array_intersect_key($data, $this->fields);
		
		$people_data['display']=1;
		
		$people_data+=uidTime();
		
		$people_data['company']=$this->company->id;

		return $this->db->update('people',$people_data,array('id'=>$people));
	}
	
	/**
	 * 为人添加标签，而不论标签是否存在
	 * @param type $people people.id
	 * @param type $label_name 标签内容或标签id（须将下方input_as_id定义为true）
	 * @param type $type 标签内容在此类对象的应用的意义，如“分类”，“类别”，案件的”阶段“等
	 * @return type 返回people_label的insert_id
	 */
	function addLabel($people,$label_name,$type=NULL){
		$result=$this->db->get_where('label',array('name'=>$label_name));

		$label_id=0;

		if($result->num_rows()==0){
			$this->db->insert('label',array('name'=>$label_name));
			$label_id=$this->db->insert_id();
		}else{
			$label_id=$result->row()->id;
		}
		
		$this->db->insert('people_label',array('people'=>$people,'label'=>$label_id,'type'=>$type,'label_name'=>$label_name));
		
		return $this->db->insert_id();
	}
	
	/**
	 * 对于指定客户，在people_label中写入一组label
	 * 对于不存在的label，当场在label表中添加
	 * @param int $people_id
	 * @param array $labels: array([$type=>]$name,...)
	 */
	function updateLabels($people_id,$labels){
		$people_id=intval($people_id);
		foreach((array)$labels as $type => $name){
			$label_id=$this->label->match($name);
			$set=array('label'=>$label_id,'label_name'=>$name);
			$where=array('people'=>$people_id);
			if(!is_integer($type)){
				$where['type']=$type;
			}
			$this->db->replace('people_label',$set+$where);
		}
	}
	
	/**
	 * 返回一个人员的指定类别的／所有的标签
	 * @param int $id people.id
	 * @param $type 为NULL时返回所有标签，为true时返回所有带类别的标签，为其他值时返回所有该类别的标签
	 * 标签的类别，指标签用于某种分类时，分类的名称，如案件的“阶段”，客户的“类别”
	 * @return array([$type=>]$label_name,...) 一个由标签类别为键名（如果标签类别存在），标签名称为键值构成的数组
	 */
	function getLabels($id,$type=NULL){
		$id=intval($id);
		
		$query="
			SELECT label.name, people_label.type
			FROM label INNER JOIN people_label ON label.id=people_label.label
			WHERE people_label.people = $id
		";
		
		if($type===true){
			$query.=" AND people_label.type IS NOT NULL";
		}
		elseif(isset($type)){
			$query.=" AND people_label.type = '$type'";
		}
		
		$result=$this->db->query($query)->result_array();
		
		$labels=array_sub($result,'name','type');
		
		return $labels;
	}
	
	/**
	 * 获得所有或指定类别的标签名称，按热门程度排序
	 * @param $type
	 * @return array([$type=>]$label_name,...) 一个由标签类别为键名（如果标签类别存在），标签名称为键值构成的数组
	 */
	function getHotLabels($type=NULL){
		$query="
			SELECT type,label_name AS name,COUNT(*) AS hits
			FROM people_label
		";
		
		if(isset($type)){
			$query.=" WHERE type='$type";
		}
		
		$query.="
			GROUP BY label
			ORDER BY hits DESC
		";
		
		return $this->db->query($query)->result_array();
	}
	
	function getHotlabelsOfTypes(){
		$hot_labels=$this->getHotLabels();
		
		$hot_labels_of_types=array();
		foreach($hot_labels as $label){
			if(isset($label['type'])){
				$hot_labels_of_types[$label['type']][]=$label['name'];
			}
		}
		return $hot_labels_of_types;
	}
	
	function addProfile($people_id,$name,$content,$comment=NULL){
		$data=array(
			'people'=>$people_id,
			'name'=>$name,
			'content'=>$content,
			'comment'=>$comment
		);
		
		$data+=uidTime(false);
		
		$this->db->insert('people_profile',$data);
		
		return $this->db->insert_id();
	}
	
	/**
	 * 对于指定人，在people_profiles中写入一组资料项
	 * @param int $people_id
	 * @param array $profiles: array($name=>$content,...)
	 */
	function updateProfiles($people_id,$profiles){
		$people_id=intval($people_id);
		
		foreach((array)$profiles as $name => $content){
			
			$set=array('content'=>$content);
			$where=array('people'=>$people_id,'name'=>$name);
			
			$this->db->update('people_profile',$set,$where);
			
			if($this->db->affected_rows()===0){
				$this->db->insert('people_profile',$set+$where+uidTime(false));
			}
			
		}
	}
	
	/**
	 * 返回一个人的资料项列表
	 * @param $people_id
	 * @return type
	 */
	function getProfiles($client_id){
		$client_id=intval($client_id);
		
		$query="
			SELECT 
				people_profile.id,people_profile.comment,people_profile.content,people_profile.name
			FROM people_profile INNER JOIN people ON people_profile.people=people.id
			WHERE people_profile.people = $client_id
		";
		return $this->db->query($query)->result_array();
	}
	
	/**
	 * 删除客户联系方式
	 */
	function removeProfile($people_id,$people_profile_id){
		$people_id=intval($people_id);
		$people_profile_id=intval($people_profile_id);
		return $this->db->delete('people_profile',array('id'=>$people_profile_id,'people'=>$people_id));
	}
	
	/**
	 * 返回一个可用的profile name列表
	 */
	function getProfileNames(){
		$query="
			SELECT name,COUNT(*) AS hits
			FROM `people_profile`
			GROUP BY name
			ORDER BY hits DESC;
		";
		
		$result=$this->db->query($query)->result_array();
		
		return array_sub($result,'name');
	}
	
	function addRelationship($people,$relative,$relation=NULL){
		$data=array(
			'people'=>$people,
			'relative'=>$relative,
			'relation'=>$relation
		);
		
		$data+=uidTime(false);
		
		$this->db->insert('people_relationship',$data);
		
		return $this->db->insert_id();
	}
	
	/**
	 * 删除相关人
	 */
	function removeRelationship($people_id,$people_relaionship_id){
		$people_id=intval($people_id);
		$people_relaionship_id=intval($people_relaionship_id);
		return $this->db->delete('people_relationship',array('id'=>$people_relaionship_id,'people'=>$people_id));
	}
	
	function getRelatives($people_id){
		$people_id=intval($people_id);
		
		$query="
			SELECT 
				people_relationship.id AS id,people_relationship.relation,people_relationship.relative,people_relationship.is_default_contact,
				people.abbreviation AS relative_name,
				phone.content AS relative_phone,email.content AS relative_email
			FROM 
				people_relationship INNER JOIN people ON people_relationship.relative=people.id
				LEFT JOIN (
					SELECT people,GROUP_CONCAT(content) AS content FROM people_profile WHERE name IN('电话','手机','固定电话') GROUP BY people
				)phone ON people.id=phone.people
				LEFT JOIN (
					SELECT people,GROUP_CONCAT(content) AS content FROM people_profile WHERE name='电子邮件' GROUP BY people
				)email ON people.id=email.people
			WHERE people_relationship.people = $people_id
			ORDER BY relation
		";
		return $this->db->query($query)->result_array();
	}
	
	function getList($method=NULL){
		$q="
			SELECT people.id,people.name,IF(people.abbreviation IS NULL,people.name,people.abbreviation) AS abbreviation,people.time,people.comment,
				phone.content AS phone,address.content AS address
			FROM people
				LEFT JOIN (
					SELECT people,GROUP_CONCAT(content) AS content FROM people_profile WHERE name IN('手机','电话') GROUP BY people
				)phone ON people.id=phone.people
				LEFT JOIN (
					SELECT people,GROUP_CONCAT(content) AS content FROM people_profile WHERE name='地址' GROUP BY people
				)address ON people.id=address.people
			WHERE display=1 AND type='客户'
		";
		$q_rows="
			SELECT COUNT(*)
			FROM people 
			WHERE display=1 AND type='客户'
		";
		$condition='';

		if($method=='potential'){
			$condition.=" AND people.id IN (SELECT people FROM people_label WHERE label_name='潜在客户')";
		
		}else{
			$condition.="
				AND people.id IN (SELECT people FROM people_label WHERE label_name='成交客户')
			";
			
			if(!$this->user->isLogged('service') && !$this->user->isLogged('developer')){
				$condition.="
					AND people.id IN (
						SELECT people FROM case_people WHERE `case` IN (
							SELECT `case` FROM case_people WHERE people = {$this->user->id}
						)
					)
				";
			}
		}
		
		$condition=$this->search($condition,array('name'=>'姓名','phone.content'=>'电话','work_for'=>'单位','address'=>'地址','comment'=>'备注'));
		$condition=$this->orderBy($condition,'time','DESC',array('abbreviation','type','address','comment'));
		$q.=$condition;
		$q_rows.=$condition;
		$q=$this->pagination($q/*,$q_rows*/);
		
		return $this->db->query($q)->result_array();
	}
	
	function isMobileNumber($number){
		if(is_numeric($number) && $number%1==0 && substr($number,0,1)=='1' && strlen($number)==11){
			return true;
		}else{
			return false;
		}
	}
	
	function getRegionByIdcard($idcard){
		$query="SELECT name FROM user_idcard_region WHERE num = '".substr($idcard,0,6)."'";
		$region = $this->db->query($query)->row()->name;
		if($region){
			return $region;
		}else{
			return false;
		}
	}
	
	function verifyIdCard($idcard){
		if(!is_string($idcard) || strlen($idcard)!=18){
			return false;
		}
		$sum=$idcard[0]*7+$idcard[1]*9+$idcard[2]*10+$idcard[3]*5+$idcard[4]*8+$idcard[5]*4+$idcard[6]*2+$idcard[7]+$idcard[8]*6+$idcard[9]*3+$idcard[10]*7+$idcard[11]*9+$idcard[12]*10+$idcard[13]*5+$idcard[14]*8+$idcard[15]*4+$idcard[16]*2;
		$mod = $sum % 11;
		$vericode_dic=array(1, 0, 'x', 9, 8, 7, 6, 5, 4, 3, 2);
		if($vericode_dic[$mod] == strtolower($idcard[17])){
			return true;
		}
	}
	
	function getGenderByIdcard($idcard){
		if(is_string($idcard) && strlen($idcard)==18){
			return $idcard[16] % 2 == 1 ? '男' : '女';
		}else{
			return false;
		}
	}
}
?>
