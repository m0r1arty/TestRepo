<?php
    
    class Aro implements IGrabber
    {
        const TAG = 5;
        const NOIMG = 'http://www.aro-market.ru/templates/pictures/no_image.jpg';
        
        private $pattern = array('0'=>60,'1'=>2,'2'=>3,'3'=>4,'4'=>5,'5'=>43,'6'=>7,'7'=>8,'8'=>9,'9'=>10,'a'=>11,'b'=>12,'c'=>13,'d'=>20,'e'=>15,'f'=>16);
        private $link;
        private $balancer = NULL;
        private $cats = array(
        'root'=>array('name'=>'Парфюмерия','ch'=>
                    array(
                        //array('name'=>'Аксессуары','url'=>'http://www.aro-market.ru/shop/type/24/'),
                        array('name'=>'Дезодорант','url'=>'http://www.aro-market.ru/shop/type/26/','spec'=>1),
                        array('name'=>'Духи','url'=>'http://www.aro-market.ru/shop/type/9/','spec'=>2),
                        array('name'=>'Одеколон','url'=>'http://www.aro-market.ru/shop/type/6/','spec'=>3),
                        array('name'=>'Парфюмированная вода','url'=>'http://www.aro-market.ru/shop/type/1/','spec'=>4),
                        array('name'=>'Туалетная вода','url'=>'http://www.aro-market.ru/shop/type/3/','spec'=>5),
                        array('name'=>'Уход','url'=>'http://www.aro-market.ru/shop/type/22/','spec'=>6),
                        array('name'=>'Тестер','url'=>'http://www.aro-market.ru/shop/type/8,7,2,4/','spec'=>7)
                    )
                )
        );
        
        private $count;
        private $process;
        
        public function __construct($dblink,$balancer)
        {
            $this->link = $dblink;
            //$this->count = 0;
            $this->process = true;
            $this->balancer = $balancer;
        }
        
        private function fgc($url)
        {
            $curl = curl_init($url);
            $cookie = DIR_LOGS.'/cookies.txt';
            curl_setopt($curl,CURLOPT_COOKIEJAR,$cookie);
            curl_setopt($curl,CURLOPT_COOKIEFILE,$cookie);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            curl_setopt($curl,CURLOPT_FOLLOWLOCATION,true);
            $return = curl_exec($curl);
            curl_close($curl);
            return $return;
        }
        
        private function processCategory($name,$parentid,&$outcatid,$url,$special)
        {
            $md5n = mb_strtoupper($name,'UTF-8');
            
            mysqli_ping($this->link);
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT id FROM grab_categories WHERE tag=? AND md5=? AND parent_id=?");
            
            $var1 = self::TAG;
            $var2 = md5($md5n);
            
            $stmt->bind_param('isi',$var1,$var2,$parentid);
            $stmt->execute();
            $stmt->store_result();

            if(mysqli_stmt_num_rows($stmt) > 0)
            {
                $stmt->bind_result($outcatid);
                $stmt->fetch();
                $stmt->free_result();
                
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("UPDATE `grab_categories` SET `url`=?,`updated`=NOW() WHERE `id`=?");
                $stmt->bind_param('si',$url,$outcatid);
                $stmt->execute();

            }else{
                $stmt->free_result();
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO grab_categories(`id`,`name`,`md5`,`tag`,`parent_id`,`url`,`updated`) VALUES(NULL,?,?,?,?,?,NOW());");
                $stmt->bind_param('ssiis',$name,$var2,$var1,$parentid,$url);
                $stmt->execute();
                $outcatid = mysqli_stmt_insert_id($stmt);
                
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO grab_special(`id`,`gid`,`spec1`) VALUES(NULL,?,?);");
                $stmt->bind_param('ii',$outcatid,$special);
                $stmt->execute();
            }
            
            $stmt->close();
        }
        
        private function processBrands($url,$parent_id,$spec)
        {
            $cid = 0;
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
            }else{
                $this->balancer->enterLevel(array());
            }
            
            $content = $this->fgc($url);
            $content = explode('<table width="100%" class="brands">',$content);
            array_shift($content);
            $content = explode('</table>',$content[0]);
            $content = array_shift($content);


            if($this->balancer->isTopLevel() && !$this->balancer->mode() && !$this->balancer->isitem('cur'))
                $this->balancer->setWorkMode();
            
            if(preg_match_all('/<a+.href="([^"]+)".+class="brand"[^>]*>([^<]+)</',$content,$arr))
            {
                foreach($arr[2] as $k=>$v)
                {
                    $v = iconv('windows-1251','utf-8',$v);
                    
                    if(!$this->balancer->mode())
                    {
                        if(md5($v) != $level['cur'])
                            continue;
                    }
                    
                    if($this->balancer->mode())
                    {
                        $this->processCategory($v,$parent_id,$cid,$arr[1][$k],$spec);
                        $this->balancer->item('cur',md5($v));
                        $this->process = $this->balancer->process();
                    }
                    
                    if(!$this->process)
                        break;
                        
                    
                    if($this->balancer->isTopLevel())
                        $this->balancer->setWorkMode();
                }
            }
            
            $this->balancer->leaveLevel();
        }

        public function collectCategories()
        {
            $catid = $cid = 0;
            
            if($this->balancer->processed())
                return;

            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(0);
                
            }else{
                $this->balancer->enterLevel(array('tag'=>self::TAG));
            }
            
            if(!$this->balancer->mode() && $this->balancer->isTopLevel() && !$this->balancer->isitem('cur'))
                $this->balancer->setWorkMode();
            
            
            $cat = $this->cats['root'];
            
            if(!$this->balancer->mode())
            {
                
                $cid = $this->balancer->gitem('cid');
            }
            
            if($this->balancer->mode())
            {
                $this->processCategory($cat['name'],0,$catid,'',0);
                $this->balancer->item('cur',md5($cat['name']));
                $this->balancer->item('cid',$catid);
                $cid = $catid;
                $this->process = $this->balancer->process();
            }
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                
                if(!$this->balancer->isitem('cur') && $this->balancer->isTopLevel())
                    $this->balancer->setWorkMode();
            }else{
                $this->balancer->enterLevel(array());
            }
            
            if($this->process)
            
            foreach($this->cats['root']['ch'] as $k=>$v)
            {
                
                if(!$this->balancer->mode())
                {
                    if($this->balancer->gitem('cur') != md5($v['name']))
                        continue;

                    $catid = $this->balancer->gitem('cid');
                }
                                
                if($this->balancer->mode())
                {
                    $this->processCategory($v['name'],$cid,$catid,str_replace('http://www.aro-market.ru','',$v['url']),$v['spec']);
                    $this->balancer->item('cur',md5($v['name']));
                    $this->balancer->item('cid',$catid);
                    $this->process = $this->balancer->process();
                }

                if(!$this->process)
                    break;
                    
                if(!$this->balancer->mode() && $this->balancer->isTopLevel())
                    $this->balancer->setWorkMode();

                $this->processBrands($v['url'],$catid,$v['spec']);
                
                if(!$this->process)
                    break;
            }
            
            $this->balancer->leaveLevel();
            $this->balancer->leaveLevel();
        }

        private function buildPath($cid,$parentid)
        {
            $arr = array();
            $level = 0;
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `path_id` FROM `category_path` WHERE `category_id`=? ORDER BY level;");
            
            $stmt->bind_param('i',$parentid);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $pid = 0;
                $stmt->bind_result($pid);
                
                while($stmt->fetch())
                {
                    $arr[] = $pid;
                }
                
                $stmt->free_result();
            }
            
            $arr[] = $cid;
            
            foreach($arr as $pid)
            {
                $stmt2 = mysqli_stmt_init($this->link);
                $stmt2->prepare("INSERT INTO `category_path`(`category_id`,`path_id`,`level`) VALUES(?,?,?)");
                $stmt2->bind_param('iii',$cid,$pid,$level);
                $stmt2->execute();
                $stmt2->close();
                $level++;
            }
                
            $stmt->close();
        }
        
        private function checkAndRebuildStruct($cid,$occid)
        {
            $tag = self::TAG;

            $stmt = mysqli_stmt_init($this->link);
            
            $stmt->prepare("UPDATE `grab_categories` SET `oc_cid`=? WHERE `tag`=? AND `id`=?");
            $stmt->bind_param('iii',$occid,$tag,$cid);
            $stmt->execute();
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->link);
            
            $stmt->prepare("SELECT `id`,`name`,`md5` FROM `grab_categories` WHERE `tag`=? AND `parent_id`=?;");
            $stmt->bind_param('ii',$tag,$cid);
            
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $id = $name = $md5 = 0;
                
                $stmt->bind_result($id,$name,$md5);
                
                while($stmt->fetch())
                {
                    $stmt2 = mysqli_stmt_init($this->link);
                    
                    $stmt2->prepare("SELECT c.`category_id` FROM `category` as c LEFT JOIN `category_description` as cd ON cd.`category_id`=c.`category_id` WHERE c.`parent_id`=? AND cd.`language_id`=4 AND MD5(UPPER(cd.`name`))=?;");
                    $stmt2->bind_param('is',$occid,$md5);
                    
                    $stmt2->execute();
                    $stmt2->store_result();

                    if($stmt2->num_rows() == 0)
                    {
                        $lang = 4;//RU
                        //нужно создать категорию
                        $stmt2->close();
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category`(`category_id`,`image`,`parent_id`,`top`,`column`,`sort_order`,`status`,`date_added`,`date_modified`) VALUES(NULL,'',?,0,1,0,1,NOW(),NOW());");
                        $stmt2->bind_param('i',$occid);
                        
                        $stmt2->execute();
                        
                        $occid2 = mysqli_stmt_insert_id($stmt2);
                        
                        $stmt2->close();
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category_description`(`category_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`) VALUES(?,?,?,'','','');");
                        $stmt2->bind_param('iis',$occid2,$lang,$name);
                        $stmt2->execute();
                        
                        $stmt2->close();
                        
                        $lang = 1;//EN
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category_description`(`category_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`) VALUES(?,?,?,'','','');");
                        $stmt2->bind_param('iis',$occid2,$lang,$name);
                        $stmt2->execute();
                        
                        $stmt2->close();
                        
                        $stmt2 = mysqli_stmt_init($this->link);
                        $stmt2->prepare("INSERT INTO `category_to_store`(`category_id`,`store_id`) VALUES(?,0);");
                        $stmt2->bind_param('i',$occid2);
                        $stmt2->execute();
                        
                        $this->buildPath($occid2,$occid);
                    }else{
                        $occid2 = 0;
                        
                        $stmt2->bind_result($occid2);
                        $stmt2->fetch();
                        $stmt2->free_result();
                    }
                    
                    $stmt2->close();
                    
                    $this->checkAndRebuildStruct($id,$occid2);
                }

                $stmt->free_result();
            }
            
            $stmt->close();
        }
        
        private function downloadImage($imgname,$url,$deleteold)
        {
            if($deleteold)
            {
                if(file_exists(DIR_IMAGE_ARO.$imgname.'.jpg'))
                    unlink(DIR_IMAGE_ARO.$imgname.'.jpg');
                if(file_exists(DIR_IMAGE_SIMA.$imgname.'.png'))
                    unlink(DIR_IMAGE_ARO.$imgname.'.png');
                if(file_exists(DIR_IMAGE_ARO.$imgname.'.gif'))
                    unlink(DIR_IMAGE_ARO.$imgname.'.gif');
            }
            
            $fname = DIR_IMAGE_ARO.$imgname;
            $tmp = explode('.',$url);
            
            switch($tmp[count($tmp)-1])
            {
                case 'jpeg':
                case 'jpg':
                    $ext = 'jpg';
                break;
                
                case 'gif':
                    $ext = 'gif';
                break;
                
                case 'png':
                    $ext = 'png';
                break;
                
                default:
                    return '';
            }
            
            $fname .= '.'.$ext;
            file_put_contents($fname,$this->fgc($url));
            
            switch($ext)
            {
                case 'jpg':
                    $imgsrc = imagecreatefromjpeg($fname);
                break;
                
                case 'png':
                    $imgsrc = imagecreatefrompng($fname);
                break;
                
                case 'gif':
                    $imgsrc = imagecreatefromgif($fname);
                break;
            }
            
            switch($ext)
            {
                case 'jpg':
                    imagejpeg($imgsrc,$fname,80);
                break;
                
                case 'png':
                    imagepng($imgsrc,$fname,8);
                break;
                case 'gif':
                    imagegif($imgsrc,$fname);
                break;
            }
            imagedestroy($imgsrc);
            
            $fname = str_replace(DIR_IMAGE_ARO,"",$fname);
            $fname = 'cache/grabber/aro/'.$fname;
            return $fname;
        }
        
        private function getProduct($md5,&$md5simg,&$gid,&$tbln,&$key)
        {
            $res = 0;
            
            for($i = 0; $i < 32; $i++)
            {
                $res += $this->pattern[$md5[$i]];
            }
            
            $table = $res % 255;
            $table++;
            $tbln = $table;
            $key = $res;
            //$res = key, table - table in database
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `id`,`pid`,`md5simg` FROM grab_p".$table." WHERE `key`=? AND `md5`=?");
            $stmt->bind_param('is',$res,$md5);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() == 0)
            {
                $stmt->close();
                return 0;
            }
            
            $pid = 0;
            $stmt->bind_result($gid,$pid,$md5simg);
            $stmt->fetch();
            $stmt->free_result();
            $stmt->close();
            return $pid;
        }
        
        private function processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc)
        {
            $oldmd5simg = '';
            $md5n = md5(mb_strtoupper($fullname,'utf-8'));
            
            $gid = 0;
            $key = 0;
            $md5simg = $nimg = '';
            $tbl = 0;
            
            $pid = $this->getProduct($md5n,$oldmd5simg,$gid,$tbl,$key);
            
            $md5simg = md5($this->fgc($img));
            
            if($noimg == false)
            {
                if($gid != 0)
                {
                    $nimg = $this->downloadImage($md5simg,$img,false);
                }else{
                    if($oldmd5simg != $md5simg)
                    {
                        $nimg = $this->downloadImage($md5simg,$img,true);
                    }
                }
            }
            
            $stmt = mysqli_stmt_init($this->link);
            $cost2 = 0;
            
            if(empty($cost))
            {
                $cost = 0;
                $dis = 1;
            }else{
                $dis = 0;
            }
            
            if($gid != 0)
            {
                $stmt->prepare("UPDATE `grab_p".$tbl."` SET `cost3`=?,`cost3d`=?,`desc`=?,`md5simg`=?,`dis`=?,`updated`=1 WHERE `id`=?;");
                $stmt->bind_param('ddssii',$cost,$cost2,$desc,$md5simg,$dis,$gid);
            }else{
                $stmt->prepare("INSERT INTO `grab_p".$tbl."`(`id`,`updated`,`key`,`md5`,`md5simg`,`cost3`,`cost3d`,`name`,`desc`,`min`,`new`,`dis`) VALUES(NULL,1,?,?,?,?,?,?,?,1,1,?);");
                $stmt->bind_param('issddssi',$key,$md5n,$md5simg,$cost,$cost2,$name,$desc,$dis);
            }
            
            $stmt->execute();
            
            if($gid == 0)
            {
                $gid = mysqli_stmt_insert_id($stmt);
            }
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT 1 FROM `grab_p2c` WHERE `gid`=? AND `tbl`=? AND `cid`=?;");
            $stmt->bind_param('iii',$gid,$tbl,$occid);
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $stmt->free_result();
            }else{
                $stmt2 = mysqli_stmt_init($this->link);
                $stmt2->prepare("INSERT INTO `grab_p2c`(`gid`,`tbl`,`cid`) VALUES(?,?,?);");
                $stmt2->bind_param('iii',$gid,$tbl,$occid);
                $stmt2->execute();
                $stmt2->close();
            }
            $stmt->close();
            
            $this->count++;
        }
        
        private function grabProduct($url,$cid,$occid,$spec,$noimg)
        {
            //echo "enter grabProduct<br/>";
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
            }else{
                $this->balancer->enterLevel(array());
            }
            
            $tmp = $this->fgc($url);
            $tmp = iconv('windows-1251','utf-8',$tmp);
            $name = '';
            $namep3 = '';
            $img = '';
            $desc = '';
            
            if(!$this->balancer->mode() && $this->balancer->isTopLevel() && !$this->balancer->isitem('cur'))
                $this->balancer->setWorkMode();
            
            if(preg_match('/<h1>([^<]+)<\/h1>/',$tmp,$arr))
            {
                $name = $arr[1];
            }
            
            $part = explode('<span style="color:#cd1384;">',$tmp);
            if($part > 3)
            {
                $part = explode('</span>',$part[2]);
                $part = $part[0];
                $md5 = md5($part);

                if($md5 != '980d44eef032fb042e5c7bd76ffcdd19' && $md5 != '08b32963db80467edf1e8017d817d91c' && $md5 != '6cdd5db762dd7664f9fc769ee39538b2')
                {
                    var_dump('fuck:'.__FILE__.',line:'.__LINE__.',sub:'.$part);
                    //die('fuck:'.__FILE__.',line:'.__LINE__.',sub:'.$part);
                }
                
                $namep3 = $part;
            }

            $part = explode('<td style="padding: 0px 0px 10px 0px;">',$tmp);
            array_shift($part);
            $part = explode('<table class="head_product">',$part[0]);
            $part = $part[0];
            
            if(preg_match('/<img.+src="([^"]+)/',$part,$arr))
            {
                $img = 'http://www.aro-market.ru'.$arr[1];
            }
            
            $part = explode('</div>',$part);
            $part = array_pop($part);
            
            $part = strip_tags($part);
            $part = trim($part);
            
            $desc = $part;
            
            $part = explode('<table class="product ">',$tmp);
            array_shift($part);
            
            if(count($part) > 0)
            {
                $tmp = explode('</table>',$part[count($part)-1]);
                $part[count($part)-1] = $tmp[0];
            }

            foreach($part as $k=>$v)
            {
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != md5($v))
                        continue;
                }
                
                if(!$this->process)
                    break;

                $type = '';
                $namep2 = '';
                $fullname = '';
                $cost = '';
                
                $tmp = explode('<td',$v);
                
                $this->balancer->item('cur',md5($v));
                
                if(preg_match('/>([^<]+)</',$tmp[1],$arr))
                {
                    $type = trim(strip_tags($arr[1]));
                }
                
                if(preg_match('/>([^<]+)</',$tmp[2],$arr))
                {
                    $namep2 = trim(strip_tags($arr[1]));
                }
                
                $fullname = $name . " ".$namep2;
                
                if(!empty($namep3))
                    $fullname .= ','.$namep3;
                
                if(preg_match('/>([^<]+)</',$tmp[4],$arr))
                {
                    $tmp2 = trim(strip_tags($arr[1]));
                    
                    if(preg_match('/([0-9]+)/',$tmp2,$arr2))
                    {
                        $cost = $arr2[1];
                    }
                }
                
                if($this->balancer->mode())
                switch($spec)
                {
                    case 1://дезодорант
                    
                        if(preg_match('/дезодорант/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                    
                    case 2://духи
                    
                        if(preg_match('/духи/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                    
                    case 3://одеколон
                    
                        if(preg_match('/одеколон/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                    
                    case 4://парфюмированная вода
                    
                        if(preg_match('/парфюмированная вода/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                    
                    case 5://туалетная вода
                    
                        if(preg_match('/туалетная вода/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                    
                    case 6://уход
                    
                        if(preg_match('/уход/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                    
                    case 7://тестер
                    
                        if(preg_match('/тестер/iu',$type))
                        {
                            $this->processProduct($cid,$occid,$fullname,$cost,$noimg,$img,$desc);
                            //echo "process:".$fullname."<br/>";
                            $this->process = $this->balancer->process();
                        }
                    
                    break;
                }

                
                if(!$this->balancer->mode())
                $this->balancer->setWorkMode();
                
                if(!$this->process)
                    break;
            }
            
            $this->balancer->leaveLevel();
            //echo "leave grabProduct".$this->balancer->processedCount()."<br/>";
        }

        private function grabProducts($url,$cid,$occid,$spec)
        {
            //echo "enter grabProducts".$this->balancer->processedCount()."<br/>";
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
                //echo 'cur level:'.$this->balancer->level()."<br/>";
                
                if(is_array($level['work']))
                {
                    $this->balancer->prevLevel();
                    return;
                }
                $tmp = explode('<table class="list_product">',iconv('utf-8','windows-1251',$level['work']));
            }else{
            
                $tmp = $this->fgc('http://www.aro-market.ru'.$url);
                $savecontent = iconv('windows-1251','utf-8',$tmp);
                $tmp = explode('<table class="list_product">',$tmp);
                
                $this->balancer->enterLevel(array('work'=>$savecontent));
                //echo 'cur level:'.$this->balancer->level()."<br/>";
            }

            if(!$this->balancer->mode() && $this->balancer->isTopLevel() && !$this->balancer->isitem('cur'))
                $this->balancer->setWorkMode();
            
            if(count($tmp) > 1)
            {
                array_shift($tmp);

                foreach($tmp as $k=>$v)
                {
                    if(!$this->process)
                        break;

                    $ov = $v;
                    if(!$this->balancer->mode())
                    {
                        if($level['cur'] != md5($v))
                            continue;
                    }

                    $noimg = false;
                    $v = explode('<td class="img">',$v);
                    $v = explode('</td>',$v[1]);
                    $v = $v[0];
                    
                    if(preg_match('/src="([^"]+)/',$v,$arr))
                    {
                        if(preg_match('/no_image\.jpg/',$arr[1]))
                        {
                            $noimg = true;
                        }
                    }
                    
                    $this->balancer->item('cur',md5($ov));
                    
                    if(preg_match('/<a.+href="([^"]+)/',$v,$arr))
                    {
                        $this->grabProduct('http://www.aro-market.ru'.$arr[1],$cid,$occid,$spec,$noimg);
                    }
                    
                    
                    if(!$this->process)
                        break;
                }
            }
            
            $this->balancer->leaveLevel();
            //echo "leave grabProducts".$this->balancer->processedCount()."<br/>";
        }
        
        private function grabCatalog($cid,$occid)
        {
            $stmt = mysqli_stmt_init($this->link);
            
            //echo "enter grabCatalog".$this->balancer->processedCount()."<br/>";
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();

                $larr = $level['work'];
                
                /*
                if($this->balancer->level() == 2)
                {
                    die(var_dump($level));
                }
                */
            }else{
            
                $larr = array();
            
                $stmt->prepare("SELECT c.`id`,c.`oc_cid`,c.`url`,sp.`spec1` FROM `grab_categories` as c LEFT JOIN `grab_special` as sp on sp.`gid`=c.`id` WHERE c.`parent_id`=? GROUP BY c.`url` ORDER BY c.`id`;");
                $stmt->bind_param('i',$cid);
            
                $stmt->execute();
                $stmt->store_result();
            
                if($stmt->num_rows() > 0)
                {
                    $url = '';
                    $id = $occid2 = $spec = 0;

                    $stmt->bind_result($id,$occid2,$url,$spec);

                    while($stmt->fetch())
                    {
                        $larr[] = array('id'=>$id,'occid2'=>$occid2,'url'=>$url,'spec'=>$spec);
                    }

                    $stmt->free_result();
                    $stmt->close();

                }else{
                    $stmt->close();
                }
                
                $this->balancer->enterLevel(array('work'=>$larr));
            }
            
            //echo "Current balancer level:".$this->balancer->level()."<br/>";
            
            if(!$this->balancer->mode() && !$this->balancer->isitem('cur') && $this->balancer->isTopLevel())
                $this->balancer->setWorkMode();
            
            $lastitem = end($larr);
            $lastitemid = $lastitem['id'];
            
            /*
            if($this->balancer->level() == 2)
            {
                echo "List item ids:<br/>";
                
                foreach($larr as $item)
                {
                    echo "id:".$item['id']."<br/>";
                }
                echo "<hr>";
            }*/
            

            /*
            if($this->balancer->level() == 2)
            foreach($larr as $k=>$item)
            {
                echo "\$k($k)=>\$item".var_export($item,true)."<br/>";
            }
            */
            
            foreach($larr as $key=>$item)
            {
                /*
                if($this->balancer->level() == 2)
                {
                    echo "ID:".$item['id']."<br/>";
                }*/
                //var_dump($key);
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != $item['id'])
                        continue;
                }

                $this->balancer->item('cur',$item['id']);

                /*if($item['id'] == 17242)
                {
                    var_dump($key);
                    die(var_dump(next($larr)));
                }*/
                
                if(!empty($item['url']))
                {
                    //echo $item['url']."<br/>\n";
                    $this->grabProducts($item['url'],$cid,$item['occid2'],$item['spec']);
                }

                /*
                if($item['id'] == 17242)
                {
                    die(var_dump(next($larr)));
                }*/

                if(!$this->process)
                {
                    //echo "cur key:".$key."<br/>";
                    break;
                }
                
                if($this->balancer->isTopLevel())
                    $this->balancer->setWorkMode();
                
                if($this->balancer->level() < 2)
                    $this->grabCatalog($item['id'],$item['occid2']);
                /*
                echo "item id:".$item['id']."<br/>";
                echo "Balancer mode:".$this->balancer->mode()."<br/>";
                echo "Process:".$this->process."<br/>";
                */
                
                /*
                if($item['id'] == 17199)
                {
                    echo "wait leave level____<br/>";
                }*/
                
                if(!$this->process || $item['id'] == $lastitemid)
                {
                    //echo "cur key:".$key."<br/>";
                    break;
                }
            }
            
            $this->balancer->leaveLevel();
            //echo "leave grabCatalog".$this->balancer->processedCount()."<br/>";
        }

        public function collectProducts()
        {
            $tag = self::TAG;
            
            if($this->balancer->processed())
                return;
            
            if(!$this->balancer->mode())
            {
                
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(0);
                
                $larr = $level['work'];
            }else{
            
                $larr = array();
            
                $stmt = mysqli_stmt_init($this->link);

                $stmt->prepare("SELECT cs.`cid`,cs.`oc_cid` FROM `grab_cat_settings` as cs WHERE `tag`=?");
                $stmt->bind_param('i',$tag);

                $stmt->execute();

                $stmt->store_result();

                if($stmt->num_rows() > 0)
                {
                    $cid = $occid = 0;

                    $stmt->bind_result($cid,$occid);

                    while($stmt->fetch())
                    {
                        $larr[] = array('cid'=>$cid,'occid'=>$occid);
                    }

                    $stmt->free_result();
                    $stmt->close();

                }else{
                    $stmt->close();
                }
                
                $this->balancer->enterLevel(array('tag'=>self::TAG,'work'=>$larr));
            }

            if(!$this->balancer->mode() && $this->balancer->isTopLevel() && !$this->balancer->isitem('cur'))
                $this->balancer->setWorkMode();
            
            foreach($larr as $item)
            {
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != $item['cid'])
                        continue;
                }

                if($this->balancer->mode())
                {
                    $this->checkAndRebuildStruct($item['cid'],$item['occid']);
                    $this->balancer->item('cur',$item['cid']);
                }
                
                if($this->balancer->isTopLevel())
                    $this->balancer->setWorkMode();
                
                if(!$this->process)
                    break;
                
                $this->grabCatalog($item['cid'],$item['occid']);

                if(!$this->process)
                    break;
            }
            
            $this->balancer->leaveLevel();
        }
        
        public function parsePre()
        {
            //
        }
        
        public function parsePost()
        {
            //
        }
    }
    
?>