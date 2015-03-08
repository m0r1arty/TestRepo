<?php

require_once('../system/library/grabberint.php');
require_once('../system/library/balancer.php');
    
    class Grabber
    {
        
        private $dbhost,$dbuser,$dbpass,$dbbase;
        private $dblink;
        private $balancer = NULL;
        private $processedproducts;
        private $process;
        
        public function __construct($host,$user,$pass,$base)
        {
            error_reporting(E_ALL);
            ini_set('display_errors',1);

            $this->dbhost = $host;
            $this->dbuser = $user;
            $this->dbpass = $pass;
            $this->dbbase = $base;
            
            $this->dblink = mysqli_connect($this->dbhost,$this->dbuser,$this->dbpass,$this->dbbase);
            mysqli_set_charset($this->dblink,"utf8");
        }
        
        public function __destruct()
        {
            mysqli_close($this->dblink);
        }
        
        public function collectCategories()
        {
            $this->balancer = new Balancer(1,$this->dblink);
            
            require_once('../system/library/grabber/Komus.php');
            $grabber = new Komus($this->dblink,$this->balancer);
            
            $grabber->collectCategories();
            unset($grabber);

            require_once('../system/library/grabber/Sima.php');
            
            $grabber = new Sima($this->dblink,$this->balancer);
            $grabber->collectCategories();
            unset($grabber);

            require_once('../system/library/grabber/Aro.php');
            
            $grabber = new Aro($this->dblink,$this->balancer);
            $grabber->collectCategories();
            unset($grabber);
            
            if($this->balancer->processed())
                echo '0';
            else
                echo '1';
        }
        
        public function parsePre()
        {
            require_once('../system/library/grabber/Komus.php');
            require_once('../system/library/grabber/Sima.php');
            require_once('../system/library/grabber/Aro.php');
            
            $grabber = new Sima($this->dblink,NULL);
            $grabber->parsePre();
            unset($grabber);
            $grabber = new Komus($this->dblink,NULL);
            $grabber->parsePre();
            unset($grabber);
            $grabber = new Aro($this->dblink,NULL);
            $grabber->parsePre();
            unset($grabber);
            
            for($i = 1; $i <= 255; $i++)
            {
                $sql = "UPDATE `grab_p".$i."` SET `dis`=0,`new`=0,`updated`=0;";
                $stmt = mysqli_stmt_init($this->dblink);
                $stmt->prepare($sql);
                $stmt->execute();
                $stmt->close();
            }
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("TRUNCATE TABLE `grab_p2c`;");
            $stmt->execute();
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("UPDATE `grab_settings` SET `processed`=0,data=''");
            $stmt->execute();
            $stmt->close();
            
            if(file_exists(DIR_CACHE.'sima.json'))
            {
                @unlink(DIR_CACHE.'sima.json');
            }
        }
        
        public function parsePost()
        {
            $this->balancer = new Balancer(3,$this->dblink);
            $this->balancer->setMax(1000);
            $this->process = true;
            
            require_once('../system/library/grabber/Komus.php');
            require_once('../system/library/grabber/Sima.php');
            require_once('../system/library/grabber/Aro.php');
            
            $grabber = new Sima($this->dblink,$this->balancer);
            $grabber->parsePost();
            unset($grabber);
            $grabber = new Komus($this->dblink,$this->balancer);
            $grabber->parsePost();
            unset($grabber);
            $grabber = new Aro($this->dblink,$this->balancer);
            $grabber->parsePost();
            unset($grabber);
            
            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(0);
            }else{
                $this->balancer->enterLevel(array('cur'=>1));
            }
            
            $this->processedproducts = 0;
            
            for($i = 1; $i<= 255; $i++)
            {
                if(!$this->balancer->mode())
                {
                    if($i != $level['cur'])
                        continue;
                }

                $this->balancer->item('cur',$i);
                $this->processTable($i);
                
                if(!$this->process)
                    break;
            }
            
            if($this->balancer->processed())
                echo '0';
            else
                echo '1';

            $this->balancer->leaveLevel();
        }
        
        private function removeProduct($pid)
        {
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_to_store` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_to_layout` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_to_download` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_to_category` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_special` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_reward` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_related` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_recurring` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_profile` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_option_value` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_option` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_image` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_filter` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_discount` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_description` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product_attribute` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("DELETE FROM `product` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
        }
        
        private function getImgUrl($md5)
        {
            $ret = '';
            
            if(empty($md5))
                return $ret;
            
            if(file_exists(DIR_IMAGE_ARO.$md5.'.jpg'))
                $ret = 'cache/grabber/aro/'.$md5.'.jpg';
            if(file_exists(DIR_IMAGE_ARO.$md5.'.png'))
                $ret = 'cache/grabber/aro/'.$md5.'.png';
            if(file_exists(DIR_IMAGE_ARO.$md5.'.gif'))
                $ret = 'cache/grabber/aro/'.$md5.'.gif';
            if(file_exists(DIR_IMAGE_KOMUS.$md5.'.jpg'))
                $ret = 'cache/grabber/komus/'.$md5.'.jpg';
            if(file_exists(DIR_IMAGE_KOMUS.$md5.'.png'))
                $ret = 'cache/grabber/komus/'.$md5.'.png';
            if(file_exists(DIR_IMAGE_KOMUS.$md5.'.gif'))
                $ret = 'cache/grabber/komus/'.$md5.'.gif';
            if(file_exists(DIR_IMAGE_SIMA.$md5.'.jpg'))
                $ret = 'cache/grabber/sima/'.$md5.'.jpg';
            if(file_exists(DIR_IMAGE_SIMA.$md5.'.png'))
                $ret = 'cache/grabber/sima/'.$md5.'.png';
            if(file_exists(DIR_IMAGE_SIMA.$md5.'.gif'))
                $ret = 'cache/grabber/sima/'.$md5.'.gif';
            
            return $ret;
        }
        
        private function addProduct($md5simg,$cost,$name,$desc,$min,$status,$gid,$tbl)
        {
            $pid = 0;
            
            $nimg = $this->getImgUrl($md5simg);
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("INSERT INTO `product`(`product_id`,`model`,`sku`,`upc`,`ean`,`jan`,`isbn`,`mpn`,`location`,`quantity`,`stock_status_id`,`image`,`manufacturer_id`,`shipping`,`price`,`points`,`tax_class_id`,`date_available`,`weight`,`weight_class_id`,`length`,`width`,`height`,`length_class_id`,`subtract`,`minimum`,`sort_order`,`status`,`date_added`,`date_modified`,`viewed`)
            VALUES(NULL,'','','','','','','','',?,5,?,0,1,?,0,0,NOW(),0,0,0,0,0,1,0,?,1,?,NOW(),NOW(),0);");
            $stmt->bind_param('isdii',$min,$nimg,$cost,$min,$status);
            $stmt->execute();
            $pid = mysqli_stmt_insert_id($stmt);
            $stmt->close();
            
            $art = 'art-'.$pid;
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("UPDATE `product` SET `model`=?,`sku`=? WHERE `product_id`=?");
            $stmt->bind_param('ssi',$art,$art,$pid);
            $stmt->execute();
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("INSERT INTO `product_to_store`(`product_id`,`store_id`) VALUES(?,0);");
            $stmt->bind_param('i',$pid);
            $stmt->execute();
            $stmt->close();
            
            foreach(array(1,4) as $lang)
            {
                $stmt = mysqli_stmt_init($this->dblink);
                $stmt->prepare("INSERT INTO `product_description`(`product_id`,`language_id`,`name`,`description`,`meta_description`,`meta_keyword`,`tag`) VALUES(?,?,?,?,'','','');");
                $stmt->bind_param('iiss',$pid,$lang,$name,$desc);
                $stmt->execute();
                $stmt->close();
            }
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("SELECT `cid` FROM `grab_p2c` WHERE `gid`=? AND `tbl`=?;");
            $stmt->bind_param('ii',$gid,$tbl);
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $cid = 0;
                $stmt->bind_result($cid);
                
                while($stmt->fetch())
                {
                    $this->productToCategory($pid,$cid);
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
            
            return $pid;
        }
        
        private function productToCategory($pid,$cid)
        {
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("INSERT INTO `product_to_category`(`product_id`,`category_id`) VALUES(?,?);");
            $stmt->bind_param('ii',$pid,$cid);
            $stmt->execute();
            $stmt->close();
        }
        
        private function updateProduct($pid,$md5simg,$cost,$desc,$min,$status,$gid,$tbl)
        {
            $nimg = $this->getImgUrl($md5simg);
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("UPDATE `product` SET `image`=?,`price`=?,`minimum`=?,`status`=?,`date_modified`=NOW() WHERE `product_id`=?");
            $stmt->bind_param('sdiii',$nimg,$cost,$min,$status,$pid);
            $stmt->execute();
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("UPDATE `product_description` SET `description`=? WHERE `product_id`=?;");
            $stmt->bind_param('si',$desc,$pid);
            $stmt->execute();
            $stmt->close();
            
            $cids = array();
            $cids2 = array();
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("SELECT `cid` FROM `grab_p2c` WHERE `gid`=? AND `tbl`=?");
            $stmt->bind_param('ii',$gid,$tbl);
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $cid = 0;
                $stmt->bind_result($cid);
                
                while($stmt->fetch())
                {
                    $cids[] = (int)$cid;
                }
                $stmt->free_result();
            }
            
            $stmt->close();
            
            $stmt = mysqli_stmt_init($this->dblink);
            $stmt->prepare("SELECT `category_id` FROM `product_to_category` WHERE `product_id`=?;");
            $stmt->bind_param('i',$pid);
            
            $stmt->execute();
            $stmt->store_result();
            
            if($stmt->num_rows() > 0)
            {
                $cid = 0;
                $stmt->bind_result($cid);
                
                while($stmt->fetch())
                {
                    $cid = (int)$cid;
                    $cids2[] = $cid;
                    
                    if(!in_array($cid,$cids))
                    {
                        //удаляем связь из product_to_category
                        $stmt2 = mysqli_stmt_init($this->dblink);
                        $stmt2->prepare("DELETE FROM `product_to_category` WHERE `product_id`=? AND `category_id`=?;");
                        $stmt2->bind_param('ii',$pid,$cid);
                        $stmt2->execute();
                        $stmt2->close();
                    }
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();
            
            //cids2 - массив идентификаторов категорий к которым товар уже был привязан
            //cids  - массив идентификаторов категорий к которым товар должен быть привязан
            
            foreach($cids as $cid)
            {
                if(!in_array($cid,$cids2))
                {
                    $stmt = mysqli_stmt_init($this->dblink);
                    $stmt->prepare("INSERT INTO `product_to_category`(`product_id`,`category_id`) VALUES(?,?);");
                    $stmt->bind_param('ii',$pid,$cid);
                }
            }
        }
        
        private function processTable($tbl)
        {
            $stmt = mysqli_stmt_init($this->dblink);
            
            $remove_arr = array();
            $new_arr = array();

            if(!$this->balancer->mode())
            {
                $this->balancer->nextLevel();
                $level = $this->balancer->getLevel(1);

                $stmt->prepare("SELECT `id`,`pid`,`md5simg`,`cost1`,`cost1d`,`cost2`,`cost2d`,`cost3`,`cost3d`,`name`,`desc`,`min`,`updated`,`new`,`dis` FROM `grab_p".$tbl."` WHERE `id`>?;");
                $stmt->bind_param('i',$level['cur']);
            }else{

                $stmt->prepare("SELECT `id`,`pid`,`md5simg`,`cost1`,`cost1d`,`cost2`,`cost2d`,`cost3`,`cost3d`,`name`,`desc`,`min`,`updated`,`new`,`dis` FROM `grab_p".$tbl."`");
                $this->balancer->enterLevel(array());
            }

            $stmt->execute();
            $stmt->store_result();
            
            $this->balancer->setWorkMode();
            
            if($stmt->num_rows() > 0)
            {
                $id = $pid = $cost1 = $cost1d = $cost2 = $cost2d = $cost3 = $cost3d = $min = $updated = $new = $dis = 0;
                $md5simg = $name = $desc = '';
                
                $stmt->bind_result($id,$pid,$md5simg,$cost1,$cost1d,$cost2,$cost2d,$cost3,$cost3d,$name,$desc,$min,$updated,$new,$dis);
                
                while($stmt->fetch())
                {
                    if(!$this->process)
                        break;

                    $this->balancer->item('cur',$id);
                    $this->processedproducts++;
                    
                    if($pid != 0 && $updated == 0)
                    {
                        $this->removeProduct($pid);
                        $remove_arr[] = $id;
                        $this->process =  $this->balancer->process();
                        continue;
                    }
                    
                    $maxcost = max($cost1,$cost1d);
                    $maxcost = max($maxcost,$cost2);
                    $maxcost = max($maxcost,$cost2d);
                    $maxcost = max($maxcost,$cost3);
                    $maxcost = max($maxcost,$cost3d);
                        
                    if($pid == 0 && $new == 1)
                    {
                        $pid = $this->addProduct($md5simg,$maxcost,$name,$desc,$min,($dis == 1)?0:1,$id,$tbl);                        
                        $new_arr[] = array('id'=>$id,'pid'=>$pid);
                        $this->process = $this->balancer->process();
                        continue;
                    }
                    
                    $this->updateProduct($pid,$md5simg,$maxcost,$desc,$min,($dis == 1)?0:1,$id,$tbl);
                    $this->process = $this->balancer->process();
                    
                    if(!$this->process)
                        break;
                }
                
                $stmt->free_result();
            }
            
            $stmt->close();

            foreach($remove_arr as $id)
            {
                $stmt = mysqli_stmt_init($this->dblink);
                $stmt->prepare("DELETE FROM `grab_p".$tbl."` WHERE `id`=?");
                $stmt->bind_param('i',$id);
                $stmt->execute();
                $stmt->close();
            }
            

            foreach($new_arr as $item)
            {
                $stmt = mysqli_stmt_init($this->dblink);
                $stmt->prepare("UPDATE `grab_p".$tbl."` SET `pid`=? WHERE `id`=?;");
                $stmt->bind_param('ii',$item['pid'],$item['id']);
                $stmt->execute();
                $stmt->close();
            }
            
            $this->balancer->leaveLevel();
        }
        
        public function collectProducts()
        {
            $this->balancer = new Balancer(2,$this->dblink);


            require_once('../system/library/grabber/Komus.php');
            
            $grabber = new Komus($this->dblink,$this->balancer);
            $grabber->collectProducts();
            unset($grabber);

            require_once('../system/library/grabber/Sima.php');
            
            $grabber = new Sima($this->dblink,$this->balancer);
            $grabber->collectProducts();
            unset($grabber);

            require_once('../system/library/grabber/Aro.php');
            
            $grabber = new Aro($this->dblink,$this->balancer);
            $grabber->collectProducts();
            unset($grabber);

            if($this->balancer->processed())
                echo '0';
            else
                echo '1';
        }
    }
    
?>