<?php

    class Sima implements IGrabber
    {
        const TAG = 2;
        
        private $pattern = array('0'=>60,'1'=>2,'2'=>3,'3'=>4,'4'=>5,'5'=>43,'6'=>7,'7'=>8,'8'=>9,'9'=>10,'a'=>11,'b'=>12,'c'=>13,'d'=>20,'e'=>15,'f'=>16);
        private $link;
        private $imgl;
        private $balancer = NULL;
        
        private $process;
        
        public function __construct($dblink,$balancer)
        {
            $this->link = $dblink;
            $this->imgl = imagecreatefromjpeg(DIR_IMAGE."cache/grabber/logox1600.jpg");

            $this->process = true;
            $this->balancer = $balancer;
        }
        
        private function fgc($url)
        {
            $curl = curl_init($url);
            $cookie = DIR_LOGS.'/cookies.txt';
            curl_setopt($curl,CURLOPT_COOKIEJAR,$cookie);
            curl_setopt($curl,CURLOPT_COOKIEFILE,$cookie);
            curl_setopt($curl,CURLOPT_FOLLOWLOCATION,true);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
            $return = curl_exec($curl);
            curl_close($curl);
            return $return;
        }
        
        private function processCategory($name,$parentid,&$outcatid,$url)
        {
            $md5n = mb_strtoupper($name,'UTF-8');
            mysqli_ping($this->link);
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `id` FROM `grab_categories` WHERE `tag`=? AND `md5`=? AND `parent_id`=?");
            
            $var1 = self::TAG;
            $var2 = md5($md5n);
            
            if(!empty($url))
            {
                if($url[0] != '/')
                    $url = '/'.$url;
                
                if($url[strlen($url)-1] != '/')
                    $url = $url . '/';
            }
            
            $stmt->bind_param('isi',$var1,$var2,$parentid);
            $stmt->execute();
            $stmt->store_result();

            if(mysqli_stmt_num_rows($stmt) > 0)
            {
                $stmt->bind_result($outcatid);
                $stmt->fetch();
                $stmt->free_result();
                
                $stmt2 = mysqli_stmt_init($this->link);
                $stmt2->prepare("UPDATE `grab_categories` SET `url`=?,`updated`=NOW() WHERE `id`=?");
                $stmt2->bind_param('si',$url,$outcatid);
                $stmt2->execute();
                $stmt2->close();

            }else{
                $stmt->free_result();
                $stmt->close();
                
                $stmt = mysqli_stmt_init($this->link);
                $stmt->prepare("INSERT INTO grab_categories(`id`,`name`,`md5`,`tag`,`parent_id`,`url`,`updated`) VALUES(NULL,?,?,?,?,?,NOW());");
                $stmt->bind_param('ssiis',$name,$var2,$var1,$parentid,$url);
                $stmt->execute();
                $outcatid = mysqli_stmt_insert_id($stmt);
            }
            
            $stmt->close();
        }
        
        private function recursiveProcessCategory($obj,$cid)
        {
            $catid = 0;
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
            }else{
                $this->balancer->enterLevel(array());
            }
            
            if(!$this->balancer->mode() && $this->balancer->isTopLevel() && !$this->balancer->isitem('cur'))
            {
                $this->balancer->setWorkMode();
            }
            
            if(isset($obj->ch))
            {
                foreach($obj->ch as $k=>$item)
                {   
                    if(!$this->balancer->mode())
                    {
                        if($level['cur'] != md5($item->n))
                            continue;
                        
                        $catid = $this->balancer->gitem('cid');
                    }
                    
                    if($this->balancer->mode())
                    {
                        $this->processCategory($item->n,$cid,$catid,(isset($item->url)?$item->url:''));
                        $this->balancer->item('cur',md5($item->n));
                        $this->balancer->item('cid',$catid);
                        $this->process = $this->balancer->process();
                    }
                    
                    if(!$this->process)
                        break;

                    if($this->balancer->isTopLevel())
                        $this->balancer->setWorkMode();
                        
                    $this->recursiveProcessCategory($item,$catid);
                    
                    if(!$this->process)
                        break;
                }
            }
            
            $this->balancer->leaveLevel();
        }

        public function collectCategories()
        {
            $catid = 0;
            
            if($this->balancer->processed())
                return;
            
            if(!file_exists(DIR_CACHE."sima.json"))
            {
            
                $content = $this->fgc('https://www.sima-land.ru/js/categories.js?mt=1422344483');
                $content = substr($content,57);
                $content = substr($content,0,strlen($content)-3);
                $json = json_decode($content);
                file_put_contents(DIR_CACHE."sima.json",serialize($json));
                $this->balancer->enterLevel(array('tag'=>self::TAG));
            }else{
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(0);
                
                if(isset($level['tag']) && (int)$level['tag'] > self::TAG)
                {
                    $this->balancer->prevLevel();
                    return;
                }
                
                $json = unserialize(file_get_contents(DIR_CACHE.'sima.json'));
            }
            
            $itemmd5 = md5(end($json)->n);
            
            foreach($json as $k=>$item)
            {
                
                if(!$this->balancer->mode())
                {
                    if(md5($item->n) != $level['cur'])
                        continue;

                    $catid = $this->balancer->gitem('cid');
                }
                
                if($this->balancer->mode())
                {
                    $this->processCategory($item->n,0,$catid,(isset($item->url)?$item->url:''));
                    $this->balancer->item('cur',md5($item->n));
                    $this->balancer->item('cid',$catid);
                    $this->process = $this->balancer->process();
                }

                if(!$this->process)
                    break;


                if($this->balancer->isTopLevel())
                    $this->balancer->setWorkMode();

                $this->recursiveProcessCategory($item,$catid);
                
                if(!$this->process)
                    break;
            }
            
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

        private function checkAndRebuildStruct($cid,$occid)//$occid - категория opencart на которую отображается категория $cid
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
        
        private function resize($fname)
        {
            $s = getimagesize($fname);
            
            if($s[0] > 800)
            {
                $prop = $s[1]/$s[0];
                $height = (int)($prop * 600);
                
                $image = new Image($fname);
                $image->resize(600,$height,'h');
                $image->save($fname);
            }
        }
        
        private function downloadImage($imgname,$url,$deleteold)
        {
            if($deleteold)
            {
                if(file_exists(DIR_IMAGE_SIMA.$imgname.'.jpg'))
                    unlink(DIR_IMAGE_SIMA.$imgname.'.jpg');
                if(file_exists(DIR_IMAGE_SIMA.$imgname.'.png'))
                    unlink(DIR_IMAGE_SIMA.$imgname.'.png');
                if(file_exists(DIR_IMAGE_SIMA.$imgname.'.gif'))
                    unlink(DIR_IMAGE_SIMA.$imgname.'.gif');
            }
            
            $fname = DIR_IMAGE_SIMA.$imgname;
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
            
            imagecopy($imgsrc,$this->imgl,0,0,0,0,270,131);
            
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
            
            $this->resize($fname);
            
            $fname = str_replace(DIR_IMAGE_SIMA,"",$fname);
            
            $fname = 'cache/grabber/sima/'.$fname;
            return $fname;
        }
        
        private function imgExists($imn)
        {
            if(!file_exists(DIR_IMAGE_SIMA.$imn.'.jpg') && !file_exists(DIR_IMAGE_SIMA.$imn.'.png') && !file_exists(DIR_IMAGE_SIMA.$imn.'.gif'))
                return false;
            
            return true;
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
        
        private function processProduct($id,$simgurl,$url,$name,$cid,$occid,$min=0)
        {
            $md5n = md5(mb_strtoupper($name,'utf-8'));
            
            $oldmd5sim = '';
            $gid = 0;
            $tbl = $key = 0;
            $exists_oc = $exists_g = false;
            
            $pid = $this->getProduct($md5n,$oldmd5sim,$gid,$tbl,$key);
            
            /*
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `product_id` FROM `product_description` WHERE MD5(UPPER(name))=?;");
            $stmt->bind_param('s',$md5n);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $pid = 0;
                $stmt->bind_result($pid);
                $exists_oc = true;
                
                $stmt->fetch();
                $stmt->free_result();
            }
            
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("SELECT `id`,`md5simg` FROM `grab_products` WHERE `md5`=?;");
            $stmt->bind_param('s',$md5n);
            $stmt->execute();
            
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $stmt->bind_result($gid,$oldmd5sim);
                $stmt->fetch();
                $stmt->free_result();
                
                $exists_g = true;
            }

            $stmt->close();
            */
            
            $tmp = $this->fgc($url);
            $desc = $imgbig = $cost = $costd = '';
            
            if(preg_match('/data-zoom-image="([^"]+)/',$tmp,$arr))
            {
                $tmp2 = str_replace('https://','http://',$arr[1]);
                $tmp2 = explode(',',$tmp2);
                $max = 0;
                
                foreach($tmp2 as $item)
                {
                    $item = trim($item);
                    
                    if(preg_match('/\/([0-9]+)\..+$/',$item,$arr2))
                    {
                        if((int)$arr2[1] > $max)
                            $imgbig = $item;
                    }
                }
            }
            
            $tmp2 = explode('<div class="old">',$tmp);
            array_shift($tmp2);
            
            $tmp2 = explode('</div>',$tmp2[0]);
            $tmp2 = trim($tmp2[0]);
            
            if(!empty($tmp2))
                $cost = (double)$tmp2;
            
            if(preg_match('/<meta.+itemprop="price".+content="([^"]+)/',$tmp,$arr))
            {
                if(!empty($tmp2))
                {
                    $costd = (double)$arr[1];
                }else{
                    $cost = (double)$arr[1];
                }
            }
            
            $tmp2 = explode('<div class="description"',$tmp);
            
            if(count($tmp2) > 1)
            {
                array_shift($tmp2);
                $tmp2 = explode('</div>',$tmp2[0]);
                $tmp2 = array_shift($tmp2);
                
                $tmp2 = explode('>',$tmp2);
                array_shift($tmp2);
                $tmp2 = trim(strip_tags(implode('>',$tmp2)));
                
                $desc = $tmp2;
                unset($tmp2);
            }
            
            $md5simg = md5($this->fgc($simgurl));
            $nimg = '';
            
            if($exists_g)
            {
                if($md5simg != $oldmd5sim || !$this->imgExists($oldmd5sim))
                {
                    $nimg = $this->downloadImage($md5simg,$imgbig,true);
                }
            }else{
                $nimg = $this->downloadImage($md5simg,$imgbig,false);
            }
            
            $stmt = mysqli_stmt_init($this->link);
            
            if($gid != 0)
            {
                $stmt->prepare("UPDATE `grab_p".$tbl."` SET `cost2`=?,`cost2d`=?,`desc`=?,`md5simg`=?,min=?,`updated`=1 WHERE `id`=?;");
                $stmt->bind_param('ddssii',$cost,$costd,$desc,$md5simg,$min,$gid);
            }else{
                $stmt->prepare("INSERT INTO `grab_p".$tbl."`(`id`,`updated`,`key`,`md5`,`md5simg`,`cost2`,`cost2d`,`name`,`desc`,`min`,`new`) VALUES(NULL,1,?,?,?,?,?,?,?,?,1);");
                $stmt->bind_param('issddssi',$key,$md5n,$md5simg,$cost,$costd,$name,$desc,$min);
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
        }
        
        private function parseProducts(&$content,&$ids,$cid,$occid)
        {
            mysqli_ping($this->link);
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
                
                if(isset($level['ids']))
                    $ids = $level['ids'];
            }else{
                $this->balancer->enterLevel(array('ids'=>$ids));
            }
            
            $tmp = explode('<div class="card-table">',$content);
            array_shift($tmp);
            
            if(count($tmp) > 0)
            {
                $tmp2 = explode("<div class='filterbar bottom-bar'>",$tmp[count($tmp)-1]);
                $tmp[count($tmp)-1] = array_shift($tmp2);
                unset($tmp2);
            }
            
            if(!$this->balancer->mode() && ((!$this->balancer->isitem('cur') && $this->balancer->isTopLevel()) || !isset($level['cur'])))
                $this->balancer->setWorkMode();
            
            foreach($tmp as $item)
            {
                $id = $min = 0;
                $url = $simgurl = '';
                
                $tmp2 = explode('</div>',$item);
                $tmp2 = $tmp2[0];
                
                if(preg_match('/href="([^"]+)"/',$tmp2,$arr))
                {
                    $url = 'http://www.sima-land.ru'.$arr[1];
                }
                
                if(preg_match('/data-hint1="([^"]+)/',$tmp2,$arr))
                {
                    $id = (int)$arr[1];
                }
                
                if($id > 0 && !$this->balancer->mode())
                {
                    if($level['cur'] != $id)
                        continue;
                }
                
                if(preg_match('/alt="([^"]+)/',$tmp2,$arr))
                {
                    $name = $arr[1];
                }
                
                if(preg_match('/data-original="([^"]+)/',$tmp2,$arr))
                {
                    $simgurl = str_replace('https://','http://',$arr[1]);
                }
                
                $tmp2 = explode('<span data-cart-min',$item);
                
                if(count($tmp2) > 1)
                {
                    $tmp2 = explode('</span>',$tmp2[1]);
                    $tmp2 = $tmp2[0];

                    if(preg_match('/>([0-9]+)$/',$tmp2,$arr))
                    {
                        $min = (int)$arr[1];
                    }
                }
                
                if($min == 0)
                    $min = 1;
                
                $this->balancer->item('cur',$id);
                
                if(!empty($url) && !empty($simgurl) && !empty($name) && $id > 0)
                {
                    if(!in_array($id,$ids))
                    {
                        $this->processProduct($id,$simgurl,$url,$name,$cid,$occid,$min);
                        $ids[] = $id;
                        $this->balancer->item('ids',$ids);
                        $this->process = $this->balancer->process();
                    }
                }
                
                if(!$this->balancer->mode())
                    $this->balancer->setWorkMode();
                
                if(!$this->process)
                    break;
            }
            
            $this->balancer->leaveLevel();
        }
        
        private function grabProducts($url,$cid,$occid,&$ids)
        {
            $tag = self::TAG;
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getCurLevel();
                
                if(!isset($level['work']))
                {
                    //похоже зашли сюда по ошибке - это уровень parseProducts
                    $this->balancer->prevLevel();
                    return;
                }
                
                $larr = $level['work'];
                
                if(isset($level['ids']))
                    $ids = $level['ids'];
            }else{

                $larr = array();

                $stmt = mysqli_stmt_init($this->link);

                $stmt->prepare("SELECT `id`,`oc_cid`,`url` FROM `grab_categories` WHERE `parent_id`=? and `tag`=?");
                $stmt->bind_param('ii',$cid,$tag);
                $stmt->execute();
                $stmt->store_result();

                if($stmt->num_rows() > 0)
                {
                    $cid2 = $occid2 = 0;
                    $url2 = '';

                    $stmt->bind_result($cid2,$occid2,$url2);

                    while($stmt->fetch())
                    {
                        $larr[] = array('cid'=>$cid2,'occid'=>$occid2,'url'=>$url2);
                    }
                    $stmt->free_result();
                }

                $stmt->close();
                $this->balancer->enterLevel(array('work'=>$larr,'ids'=>$ids));
            }
            
            foreach($larr as $item)
            {
                
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != $item['cid'])
                        continue;
                    
                    if($this->balancer->isitem('ids'))
                        $ids = $this->balancer->gitem('ids');
                }
                
                if(!$this->process)
                    break;

                $this->balancer->item('cur',$item['cid']);
                $this->grabProducts($item['url'],$item['cid'],$item['occid'],$ids);
                $this->balancer->item('ids',$ids);
            }

            $tmp = $this->fgc('http://www.sima-land.ru'.$url);
            
            if(strpos($tmp,'<div class="card">') === false)
            {
                $this->balancer->leaveLevel();
                return;
            }
            
            //ищем страницы по которым можно перемещаться
            $pages = array();
            $maxpage = 0;
            
            $content = $tmp;
            $tmp = explode('<div class="pagination">',$tmp);
            
            if(count($tmp) > 1)
            {
                $tmp = explode('</div>',$tmp[1]);
                $tmp = $tmp[0];
                
                if(preg_match_all('/<a.+class="([^"]+)"\s?.+href="([^"]+)"/ui',$tmp,$arr))
                {
                    foreach($arr[2] as $pl)
                    {
                        if(preg_match('/p([0-9]+)\/$/',$pl,$arr2))
                        {
                            if((int)$arr2[1] > $maxpage)
                                $maxpage = (int)$arr2[1];
                        }
                    }

                    for($i = 2; $i <= $maxpage; $i++)
                    {
                        $pages[] = 'http://www.sima-land.ru'.$url.'p'.$i.'/';
                    }
                }
            }

            $pagei = 0;
            $need = true;
			
            do
            {
                if(!$this->process)
                    break;
                
                if(!$this->balancer->mode() && isset($level['page']) && (int)$level['page'] > 0)
                {
                    if($level['page'] != $pagei)
                    {
                        $pagei++;
                        $nitem = array_shift($pages);
                        continue;
                    }
                    
                    $content = $this->fgc($nitem);
                }

                $this->balancer->item('page',$pagei);

                $this->parseProducts($content,$ids,$cid,$occid);
                $this->balancer->item('ids',$ids);
				$this->balancer->forceSave();
                
                if(count($pages) > 0)
                {
                    $nitem = array_shift($pages);
                    $content = $this->fgc($nitem);
                }else{
                    $need = false;
                }
                
                if(!$this->process)
                    break;
				
				$pagei++;
            }while($need);
            
            $this->balancer->leaveLevel();
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
                
                if((int)$level['tag'] > self::TAG)
                {
                    $this->balancer->prevLevel();
                    return;
                }
                
                $larr = $level['work'];
            }else{
            
                $larr = array();

                $stmt = mysqli_stmt_init($this->link);

                $stmt->prepare("SELECT cs.`cid`,cs.`oc_cid`,c.`url` FROM `grab_cat_settings` as cs LEFT JOIN `grab_categories` as c ON cs.cid=c.id WHERE cs.`tag`=?");
                $stmt->bind_param('i',$tag);

                $stmt->execute();
                $stmt->store_result();

                if($stmt->num_rows() > 0)
                {
                    $cid = $occid = 0;
                    $url = '';
                    $stmt->bind_result($cid,$occid,$url);

                    while($stmt->fetch())
                    {
                        $larr[] = array('cid'=>$cid,'occid'=>$occid,'url'=>$url);
                    }

                    $stmt->free_result();
                }

                $stmt->close();
                
                $this->balancer->enterLevel(array('tag'=>self::TAG,'work'=>$larr));
            }
            
            foreach($larr as $item)
            {
                if(!$this->balancer->mode())
                {
                    if($level['cur'] != $item['cid'])
                        continue;
                }
                $ids = array();
                
                if($this->balancer->mode())
                {
                    $this->checkAndRebuildStruct($item['cid'],$item['occid']);
                }
                
                $this->balancer->item('cur',$item['cid']);
                
                $this->grabProducts($item['url'],$item['cid'],$item['occid'],$ids);
                
                if(!$this->process)
                    break;
                
                unset($ids);
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