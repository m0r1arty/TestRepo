<?php
    
    class GrabberSettings
    {
        
        private $dbhost,$dbuser,$dbpass,$dbbase;
        private $dblink;
        private $srcs = array(1=>'komus.ru',2=>'sima-land.ru',3=>'aro-market.ru');
        
        public function __construct($host,$user,$pass,$base)
        {
            $this->dbhost = $host;
            $this->dbuser = $user;
            $this->dbpass = $pass;
            $this->dbbase = $base;
            
            $this->dblink = mysqli_connect($this->dbhost,$this->dbuser,$this->dbpass,$this->dbbase);
            mysqli_set_charset($this->dblink,"utf8");
            ob_start();
        }
        
        private function get_rec_cat_name(&$name,$id)
        {
            $stmt = mysqli_stmt_init($this->dblink);


            $stmt->prepare("SELECT cd.name,c.parent_id FROM category as c LEFT JOIN category_description as cd ON cd.category_id = c.category_id WHERE c.category_id=?");
            
            $stmt->bind_param('i',$id);
            
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $pid = 0;
                $nm = '';
                
                $stmt->bind_result($nm,$pid);
                $stmt->fetch();
                
                if(!empty($name))
                {
                    $name = $nm."&nbsp;>>&nbsp;".$name;
                }else{
                    $name = $nm;
                }
                
                if($pid !== 0)
                {
                    $this->get_rec_cat_name($name,$pid);
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        private function get_rec_cat_src_name(&$name,$id)
        {
            $stmt = mysqli_stmt_init($this->dblink);


            $stmt->prepare("SELECT `name`,`parent_id` FROM `grab_categories` WHERE `id`=?");
            
            $stmt->bind_param('i',$id);
            
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $pid = 0;
                $nm = '';
                
                $stmt->bind_result($nm,$pid);
                $stmt->fetch();
                
                if(!empty($name))
                {
                    $name = $nm."&nbsp;>>&nbsp;".$name;
                }else{
                    $name = $nm;
                }
                
                if($pid !== 0)
                {
                    $this->get_rec_cat_src_name($name,$pid);
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        private function fillTable()
        {
            $stmt = mysqli_stmt_init($this->dblink);
            
            $stmt->prepare("SELECT id,cid,oc_cid,tag FROM grab_cat_settings");
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $id = $cid = $occid = $tag = 0;
                
                $stmt->bind_result($id,$cid,$occid,$tag);
                
                while($stmt->fetch())
                {
                    $srcname = $catname = '';
                    $this->get_rec_cat_name($catname,$occid);
                    $this->get_rec_cat_src_name($srcname,$cid);
                    ?>
                    <tr>
                        <td>
                            <div>
                                <table width="100%">
                                    <tr>
                                        <td>OpenCart:</td>
                                        <td><?php echo $catname; ?></td>
                                    </tr>
                                    <tr>
                                        <td>Source:</td>
                                        <td><?php echo $srcname; ?></td>
                                    </tr>
                                </table>
                            </div>
                        </td>
                        <td align="center"><?php echo $this->srcs[$tag]; ?></td>
                        <td align="center">
                        <div>
                            <table width="100%">
                                <tr>
                                    <td><a href="#" class="editclass" onclick="javascript:return editfunc(<?php echo $id; ?>);">Редактировать</a></td>
                                </tr>
                                <tr>
                                    <td><a href="#" class="delclass" onclick="javascript:return delfunc(<?php echo $id; ?>);">Удалить</a></td>
                                </tr>
                            </table>
                        </div>
                        </td>
                    </tr>
                    <?php
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        public function def()
        {
            ?>
            <form method="post" action="/export/gsettings.php" id="editform">
                <input type="hidden" name="action" value="edit"/>
                <input type="hidden" name="id" value="0"/>
            </form>
            <form method="post" action="/export/gsettings.php" id="delform">
                <input type="hidden" name="action" value="delete"/>
                <input type="hidden" name="id" value="0"/>
            </form>
            <script type="text/javascript">
            function editfunc(id)
            {
                $("input[name='id']","#editform").val(id);
                $("#editform").submit();
                return false;
            }
            
            function delfunc(id)
            {
                if(confirm('Действительно хотите удалить запись?'))
                {
                    $("input[name='id']","#delform").val(id);
                    $("#delform").submit();
                }
                return false;
            }
            </script>
            <ul style="list-style-type:none;">
                <li><a class="addlink" href="#">Добавить</a></li>
            </ul>
            <div style="text-align:center;">
                <h2>Маппинг категорий</h2>
                <table cellpadding="0" cellspacing="0" width="100%">
                    <tr>
                        <th width="80%">Категории</th>
                        <th>Источник</th>
                        <th>Действия</th>
                    </tr>
                    <?php
                        $this->fillTable();
                    ?>
                </table>
            </div>
            <?php
        }
        
        public function header()
        {
            ?>
            <html>
                <head>
                    <title>Grabber settings</title>
                    <meta http-equiv=Content-Type content="text/html; charset=utf-8">
                </head>
                <body>
                <form action="/export/gsettings.php" method="post" id="addform">
                    <input type="hidden" name="action" value="add">
                </form>
                <script type="text/javascript" src="/js/jquery-1.11.2.min.js"> </script>
                <script type="text/javascript">
                    
                    $(document).ready(function()
                    {
                        $(".addlink").click(function()
                        {
                            $("#addform").submit();
                            return false;
                        });
                    });
                    
                </script>
            <?php
        }
        
        public function footer()
        {
            ?>
            </body>
            </html>
            <?php
        }
        
        private function rec_getcat(&$arr,$name,$pid)
        {
            $catid = $catname = 0;
            
            $stmt = mysqli_stmt_init($this->dblink);
            
            $stmt->prepare("SELECT c.`category_id`,cd.`name` FROM category as c LEFT JOIN category_description as cd ON cd.`category_id`=c.`category_id` WHERE cd.`language_id`=4 AND c.`parent_id`=? ORDER BY cd.`name`");//4 - RU lang
            
            $stmt->bind_param('i',$pid);
            
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $stmt->bind_result($catid,$catname);
                
                while($stmt->fetch())
                {
                    $arr[] = array('name'=>$name."&nbsp;>>&nbsp;".$catname,'id'=>$catid);
                    $this->rec_getcat($arr,$name."&nbsp;>>&nbsp;".$catname,$catid);
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        public function printoc_categories($ocid=0)
        {
            $catid = $catname = 0;
            $arr = array();
            
            $stmt = mysqli_stmt_init($this->dblink);
            
            $stmt->prepare("SELECT c.`category_id`,cd.`name` FROM category as c LEFT JOIN category_description as cd ON cd.`category_id`=c.`category_id` WHERE cd.`language_id`=4 AND c.`parent_id`=0 ORDER BY cd.`name`");//4 - RU lang
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $stmt->bind_result($catid,$catname);
                
                while($stmt->fetch())
                {
                    $arr[] = array('name' => $catname,'id'=>$catid);
                    $this->rec_getcat($arr,$catname,$catid);
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
            
            foreach($arr as $k=>$v)
            {
                echo "<option value='".$v['id']."'".(($ocid != 0 && $ocid == $v['id'])?' selected':'').">".$v['name']."</option>";
            }
        }
        
        private function get_rec_src_ids(&$arr,$id)
        {
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("SELECT parent_id FROM grab_categories WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $pid = 0;
                $stmt->bind_result($pid);
                $stmt->fetch();
                
                $arr[] = $id;
                
                if($pid != 0)
                    $this->get_rec_src_ids($arr,$pid);
                
                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        private function get_map_info($id,&$catid,&$ocid,&$tag)
        {
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("SELECT cid,oc_cid,tag FROM grab_cat_settings WHERE id=?");
            $stmt->bind_param('i',$id);
            $stmt->execute();
            $stmt->store_result();
            if($stmt->num_rows() > 0)
            {
                $stmt->bind_result($catid,$ocid,$tag);
                $stmt->fetch();
                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        public function edit()
        {
            $arr = array();
            $id = (int)$_POST['id'];
            
            if($id == 0)
                return;
            
            $this->get_map_info($id,$cid,$ocid,$tag);
            $this->get_rec_src_ids($arr,$cid);
            
            $arr = array_reverse($arr);
            $jsarr = "[".implode(',',$arr)."]";
          ?>
            <div align="center">
                <h2>Редактирование маппинга категорий</h2>
                <form action="/export/gsettings.php" id="ajaxaddform" method="post">
                    <div id="top">
                    <input type="hidden" name="id" value="<?php echo $id; ?>"/>
                    <input type="hidden" name="action" value="edit"/>
                    <input type="hidden" name="gid" value="0" id="gid"/>
                    <div style="padding-bottom: 40px;">Категория OpenCart:<select name="ocid">
                        <?php
                            $this->printoc_categories($ocid);
                        ?>
                    </select>
                    </div>
                    <div>
                        <select id="aselectsrc" name="selectsrc">
                            <option value="0">Выберите источник</option>
                            <option value="3"<?php if($tag == 3) echo ' selected';?>>aro-market.ru</option>
                            <option value="1"<?php if($tag == 1) echo ' selected';?>>komus.ru</option>
                            <option value="2"<?php if($tag == 2) echo ' selected';?>>sima-land.ru</option>
                        </select>
                    </div>
                    </div>
                    <input type="submit" value="Изменить"/>
                </form>
            </div>
            <script type="text/javascript">
            
            done = 0;
            steps = <?php echo $jsarr; ?>;
            step = 0;
            
            function catchange()
            {
                var val = parseInt(this.options[this.options.selectedIndex].value);
                var parent = $(this).parent();
                
                while(parent.next().length)
                {
                    parent.next().remove();
                }

                if(val !== 0)
                {
                    $("#gid").val(val);
                    
                    $.ajax('/export/gsettings.php',
                    {
                        type : 'POST',
                        success : function(data)
                        {
                            var str,selected,i;
                            
                            try
                            {
                                if(data.length == 0)
                                    return;
                                
                                str = '<div><div><h3>Категория:</h3></div><select id="cat_'+val+'" class="cat"><option value="0">Выберите подкатегорию</option>';
                                
                                for(i = 0; i < data.length; i++)
                                {
                                    if(!done && steps.length > step && data[i].id == steps[step])
                                    {
                                        selected = ' selected';
                                        step++;
                                    }else{
                                        selected = '';
                                    }
                                    str += '<option value="'+data[i].id+'"'+selected+'>'+data[i].name+'</option>';
                                }
                                
                                str += '</select></div>';
                                
                                $("#top").append(str);
                                $(".cat").off('change');
                                $(".cat").change(catchange);
                                
                                if(step == steps.length)
                                    done = 1;

                                if(!done)
                                {
                                    window.setTimeout(function(){$("#cat_"+val).trigger('change');},500);
                                }
                            }catch(err)
                            {
                                alert(data);
                            }
                        },
                        data : {action : 'ajax',type : 'getcat',parent : val}
                    }
                    );
                }else{
                    //иначе берем значение parent
                    if(parent.prev().length)
                    {
                        var pp = parent.prev();
                        var ss = $("select",pp).get(0);
                        var val = parseInt(ss.options[ss.options.selectedIndex].value);
                        
                        if($("select",pp).attr('name') == 'selectsrc')
                        {
                            $("#gid").val(0);
                        }else{
                            $("#gid").val(val);
                        }
                    }
                }
            }
            
                $(document).ready(function()
                {
                    $("#aselectsrc").change(function()
                    {
                        var val = parseInt(this.options[this.options.selectedIndex].value);
                        var parent = $(this).parent();
                        
                        $("#gid").val(0);
                        
                        while(parent.next().length)
                        {
                            parent.next().remove();
                        }
                        
                        if(val !== 0)
                        {
                                                        
                            $.ajax('/export/gsettings.php',{
                                type : 'POST',
                                success : function(data)
                                {
                                    var str,selected;
                                    try
                                    {
                                        if(data.length == 0)
                                            return;

                                        str = '<div><div><h3>Корневая категория:</h3></div><select id="cat_0" class="cat"><option value="0">Выберите категорию</option>';
                                        for(var i = 0; i < data.length; i++)
                                        {
                                            if(!done && steps.length != step && data[i].id == steps[step])
                                            {
                                                selected = ' selected';
                                                step++;
                                            }else{
                                                selected = '';
                                            }
                                            str += '<option value="'+data[i].id+'"'+selected+'>'+data[i].name+'</option>';
                                        }
                                        str += '</select></div>';
                                        
                                        $("#top").append(str);
                                        $(".cat").off('change');
                                        $(".cat").change(catchange);
                                        
                                        if(step == steps.length)
                                            done = 1;
                                        
                                        window.setTimeout(function(){$("#cat_0").trigger('change');},500);
                                    }catch(err)
                                    {
                                        alert(data);
                                    }
                                },
                                data : {action : 'ajax',type : 'getroot',source : val}
                            });
                        }
                    });
                    $("#aselectsrc").trigger('change');
                });
            </script>
            <?php
        }
        
        public function add()
        {
            ?>
            <div align="center">
                <h2>Добавление маппинга категорий</h2>
                <form action="/export/gsettings.php" id="ajaxaddform" method="post">
                    <div id="top">
                    <input type="hidden" name="action" value="add">
                    <input type="hidden" name="gid" value="0" id="gid">
                    <div style="padding-bottom: 40px;">Категория OpenCart:<select name="ocid">
                        <?php
                            $this->printoc_categories();
                        ?>
                    </select>
                    </div>
                    <div>
                        <select id="aselectsrc" name="selectsrc">
                            <option value="0">Выберите источник</option>
                            <option value="3">aro-market.ru</option>
                            <option value="1">komus.ru</option>
                            <option value="2">sima-land.ru</option>
                        </select>
                    </div>
                    </div>
                    <input type="submit" value="Добавить"/>
                </form>
            </div>
            <script type="text/javascript">
            
            function catchange()
            {
                var val = parseInt(this.options[this.options.selectedIndex].value);
                var parent = $(this).parent();
                
                while(parent.next().length)
                {
                    parent.next().remove();
                }

                if(val !== 0)
                {
                    $("#gid").val(val);
                    
                    $.ajax('/export/gsettings.php',
                    {
                        type : 'POST',
                        success : function(data)
                        {
                            var str;
                            
                            try
                            {
                                if(data.length == 0)
                                    return;
                                
                                str = '<div><div><h3>Категория:</h3></div><select id="cat_'+val+'" class="cat"><option value="0">Выберите подкатегорию</option>';
                                
                                for(var i = 0; i < data.length; i++)
                                {
                                    str += '<option value="'+data[i].id+'">'+data[i].name+'</option>';
                                }
                                
                                str += '</select></div>';
                                
                                $("#top").append(str);
                                $(".cat").off('change');
                                $(".cat").change(catchange);
                                
                            }catch(err)
                            {
                                alert(data);
                            }
                        },
                        data : {action : 'ajax',type : 'getcat',parent : val}
                    }
                    );
                }else{
                    //иначе берем значение parent
                    if(parent.prev().length)
                    {
                        var pp = parent.prev();
                        var ss = $("select",pp).get(0);
                        var val = parseInt(ss.options[ss.options.selectedIndex].value);
                        
                        if($("select",pp).attr('name') == 'selectsrc')
                        {
                            $("#gid").val(0);
                        }else{
                            $("#gid").val(val);
                        }
                    }
                }
            }
            
                $(document).ready(function()
                {
                    $("#aselectsrc").change(function()
                    {
                        var val = parseInt(this.options[this.options.selectedIndex].value);
                        var parent = $(this).parent();
                        
                        $("#gid").val(0);
                        
                        while(parent.next().length)
                        {
                            parent.next().remove();
                        }
                        
                        if(val !== 0)
                        {
                                                        
                            $.ajax('/export/gsettings.php',{
                                type : 'POST',
                                success : function(data)
                                {
                                    var str;
                                    try
                                    {
                                        if(data.length == 0)
                                            return;

                                        str = '<div><div><h3>Корневая категория:</h3></div><select id="cat_0" class="cat"><option value="0">Выберите категорию</option>';
                                        for(var i = 0; i < data.length; i++)
                                        {
                                            str += '<option value="'+data[i].id+'">'+data[i].name+'</option>';
                                        }
                                        str += '</select></div>';
                                        
                                        $("#top").append(str);
                                        $(".cat").off('change');
                                        $(".cat").change(catchange);
                                        
                                    }catch(err)
                                    {
                                        alert(data);
                                    }
                                },
                                data : {action : 'ajax',type : 'getroot',source : val}
                            });
                        }
                    });
                });
            </script>
            <?php
        }
        
        public function ajax()
        {
            ob_end_clean();
            
            switch($_POST['type'])
            {
                case 'getroot':
                
                    $type = (int)$_POST['source'];
                    $r_id = $r_name = $r_pid = 0;
                    $retarr = array();
                    
                    $stmt = mysqli_stmt_init($this->dblink);
                    $stmt->prepare("SELECT `id`,`name`,`parent_id` FROM grab_categories WHERE tag = ? AND parent_id=0");
                    $stmt->bind_param('i',$type);
                    
                    $stmt->execute();
                    
                    $stmt->store_result();
                    
                    if($stmt->num_rows() > 0)
                    {
                        $stmt->bind_result($r_id,$r_name,$r_pid);
                        
                        while($stmt->fetch())
                        {
                            $retarr[] = array('name'=>$r_name,'parent_id'=>$r_pid,'id'=>$r_id);
                        }
                        
                        $stmt->free_result();
                    }
                    
                    $stmt->close();
                    
                    header('Content-type: application/json;charset=utf-8');
                    echo json_encode($retarr);
                
                break;
                
                case 'getcat':
                
                    $parent = (int)$_POST['parent'];
                    $r_id = $r_name = $r_pid = 0;
                    $retarr = array();
                    
                    $stmt = mysqli_stmt_init($this->dblink);
                    $stmt->prepare("SELECT `id`,`name`,`parent_id` FROM grab_categories WHERE parent_id = ?");
                    $stmt->bind_param('i',$parent);
                    
                    $stmt->execute();
                    
                    $stmt->store_result();
                    
                    if($stmt->num_rows() > 0)
                    {
                        $stmt->bind_result($r_id,$r_name,$r_pid);
                        
                        while($stmt->fetch())
                        {
                            $retarr[] = array('name'=>$r_name,'parent_id'=>$r_pid,'id'=>$r_id);
                        }
                        
                        $stmt->free_result();
                    }
                    
                    $stmt->close();
                    
                    header('Content-type: application/json;charset=utf-8');
                    echo json_encode($retarr);
                
                break;
            }
            
            exit;
        }
        
        private function getTagByCID($cid)
        {
            $tag = 0;
            $stmt = mysqli_stmt_init($this->dblink);
            
            $stmt->prepare('SELECT tag FROM grab_categories WHERE id=?');

            $stmt->bind_param('i',$cid);
                
            $stmt->execute();
            $stmt->store_result();

            if($stmt->num_rows() > 0)
            {
                $stmt->bind_result($tag);
                $stmt->fetch();
                $stmt->free_result();
            }
            
            $stmt->close();
            return $tag;
        }
        
        private function processAdd()
        {
            if(isset($_POST['gid']) && isset($_POST['ocid']))
            {
                $gid = (int)$_POST['gid'];
                $ocid = (int)$_POST['ocid'];
                
                if($ocid > 0 && $gid > 0)
                {
                    $tag = $this->getTagByCID($gid);

                    if($tag != 0)
                    {
                        $stmt = mysqli_stmt_init($this->dblink);
                        
                        $stmt->prepare('INSERT INTO `grab_cat_settings`(`cid`,`oc_cid`,`tag`) VALUES(?,?,?);');
                        $stmt->bind_param('iii',$gid,$ocid,$tag);
                        
                        $stmt->execute();
                        
                        $stmt->close();
                    }
                }
                
                header('Location: /export/gsettings.php');
                exit;
            }
        }
        
        private function processEdit()
        {
            if(isset($_POST['gid']) && isset($_POST['ocid']))
            {
                $id = (int)$_POST['id'];
                $gid = (int)$_POST['gid'];
                $ocid = (int)$_POST['ocid'];
                
                if($ocid > 0 && $gid > 0 && $id > 0)
                {
                    $tag = $this->getTagByCID($gid);
                    
                    if($tag != 0)
                    {
                        $stmt = mysqli_stmt_init($this->dblink);
                        
                        $stmt->prepare("UPDATE `grab_cat_settings` SET `cid`=?,`oc_cid`=?,`tag`=? WHERE `id`=?");
                        
                        $stmt->bind_param('iiii',$gid,$ocid,$tag,$id);
                        $stmt->execute();
                        $stmt->close();
                    }
                    
                    header('Location: /export/gsettings.php');
                    exit;
                }
            }
        }
        
        private function processDelete()
        {
            $id = (int)$_POST['id'];
            
            if($id > 0)
            {
                $stmt = mysqli_stmt_init($this->dblink);
                $stmt->prepare('DELETE FROM `grab_cat_settings` WHERE `id`=?');
                $stmt->bind_param('i',$id);
                $stmt->execute();
                $stmt->close();
                
                header('Location: /export/gsettings.php');
                exit;
            }
        }
        
        public function dispatchRequest()
        {
            $this->header();
            
            if(isset($_POST['action']))
            {
                switch($_POST['action'])
                {
                    case 'add':
                        
                        $this->processAdd();
                        $this->add();
                        
                    break;
                    
                    case 'edit':

                        $this->processEdit();
                        $this->edit();
                    
                    break;
                    
                    case 'delete':
                    
                        $this->processDelete();
                    
                    break;
                    
                    case 'ajax':
                        
                        $this->ajax();
                        
                    break;
                }
            }else{
                $this->def();
            }
            
            $this->footer();
        }
    }
    
?>