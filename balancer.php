<?php

class Balancer{
    
    const MAX = 10;
    
    private $type = 0;
    private $mode = 0;
    private $link = NULL;
    private $processed = 0;
    private $curlevel = -1;
    private $levels = array();
    private $max = 0;
    
    public function __construct($type,$dblink)
    {
        switch($type)
        {
            case 1:
                $this->type = $type;
            break;
            case 2:
                $this->type = $type;
            break;
            case 3:
                $this->type = $type;
            break;
            default:
                throw new Exception('Unknown type');
            break;
        }
        
        $this->max = self::MAX;
        $this->link = $dblink;
        
        $stmt = mysqli_stmt_init($this->link);
        $stmt->prepare("SELECT `err`,`data` FROM `grab_settings` WHERE `id`=?");
        $stmt->bind_param('i',$this->type);
        $stmt->execute();
        $stmt->store_result();
        
        $data = '';
        $err = 0;
        
        if($stmt->num_rows() > 0)
        {
            $stmt->bind_result($err,$data);
            $stmt->fetch();
            $stmt->free_result();
        }
        
        $stmt->close();
        
        if($err == 1)
        {
            throw new Exception('Error was detected');
            exit;
        }
        
        if(empty($data))
        {
            $this->mode = 1;
        }else{
            $this->mode = 0;
            $this->levels = unserialize($data);
        }
    }
    
    public function mode()
    {
        return $this->mode;
    }
    
    public function setWorkMode()
    {
        $this->mode = 1;
    }
    
    public function getLevel($lvl)
    {
        if((int)$lvl >= count($this->levels))
            return array();
        else
            return $this->levels[$lvl];
    }
    
    public function getCurLevel()
    {
        return $this->getLevel($this->curlevel);
    }
    
    public function nextLevel()
    {
        $this->curlevel++;
    }
    
    public function prevLevel()
    {
        $this->curlevel--;
    }
    
    public function cleanLevels()
    {
        $this->levels = array();
    }
    
    public function enterLevel($level)
    {
        array_push($this->levels,$level);
        $this->curlevel++;
    }
    
    
    public function leaveLevel()
    {
        array_pop($this->levels);
        $this->curlevel--;
    }
    
    public function dumpLevel()
    {
        var_dump("curLevel:",$this->curlevel);
        var_dump("levels:",count($this->levels));
    }
    
    public function isTopLevel()
    {
        if($this->curlevel == (count($this->levels)-1))
            return true;
        
        return false;
    }
    
    public function level()
    {
        return $this->curlevel;
    }
    
    public function isitem($name)
    {
        return isset($this->levels[$this->curlevel][$name])?1:0;
    }
    
    public function item($name,$val)
    {
        if($this->curlevel == -1)
            return;
        
        $this->levels[$this->curlevel][$name] = $val;
    }
    
    public function gitem($name)
    {
        if($this->curlevel == -1)
            return;
        
        return $this->levels[$this->curlevel][$name];
    }
    
    public function processed()
    {
        return ($this->processed >= $this->max)?1:0;
    }
    
    public function processedCount()
    {
        return $this->processed;
    }
    
    public function setMax($max)
    {
        $this->max = $max;
    }
    
    public function process()
    {
        $this->processed++;
        
        $stmt = mysqli_stmt_init($this->link);
        $stmt->prepare("UPDATE `grab_settings` SET `processed`=`processed`+1 WHERE `id`=?");
        $stmt->bind_param('i',$this->type);
        $stmt->execute();
        $stmt->close();
        
        if($this->processed >= $this->max)
        {
            //save
            $stmt = mysqli_stmt_init($this->link);
            $stmt->prepare("UPDATE `grab_settings` SET `data`=? WHERE `id`=?");
            $s = serialize($this->levels);
            $stmt->bind_param('si',$s,$this->type);
            $stmt->execute();
            $stmt->close();
            return false;
        }
        
        return true;
    }
	
	public function forceSave()
	{
		$stmt = mysqli_stmt_init($this->link);
		$stmt->prepare("UPDATE `grab_settings` SET `data`=? WHERE `id`=?");
		$s = serialize($this->levels);
		$stmt->bind_param('si',$s,$this->type);
		$stmt->execute();
		$stmt->close();
	}
}

?>