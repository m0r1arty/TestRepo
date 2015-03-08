<?php
    
    interface IGrabber
    {
        public function collectCategories();
        public function collectProducts();
        public function parsePre();
        public function parsePost();
    }
    
?>