<?php
require_once __DIR__.'/../live2/templates.php';
class TemplatePDO extends PDO {
    public string $text='<p>Beste [contactpersoon], zie [busbriefje]</p>';
    public bool $race=false;
    public array $rows=[['id'=>1,'email_template'=>'Eerste tekst'],['id'=>5,'email_template'=>'Afronding']];
    public function __construct() {}
    public function query(string $query, ?int $fetchMode=null, mixed ...$args): PDOStatement|false {return new TemplateStatement($this,$query);}
    public function prepare(string $query,array $options=[]): PDOStatement|false { return new TemplateStatement($this,$query); }
}
class TemplateStatement extends PDOStatement {
    private int $affected=0;
    public function __construct(private TemplatePDO $db,private string $sql) {}
    public function execute(?array $params=null): bool {
        if (str_starts_with($this->sql,'UPDATE')) {
            if ($this->db->race) $this->db->text='Andere tekst';
            if($this->db->text===$params[2]) {$this->db->text=$params[0];$this->affected=1;}
        }
        return true;
    }
    public function fetchColumn(int $column=0): mixed {return $this->db->text;}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {return $this->db->rows;}
    public function rowCount(): int {return $this->affected;}
}
function checkTemplate(bool $ok): void {if(!$ok) throw new RuntimeException('Sjablooncontrole mislukt');}
function rejectTemplate(callable $fn): void {try{$fn();}catch(InvalidArgumentException|DomainException $e){return;}throw new RuntimeException('Ongeldige opslag geaccepteerd');}
$db=new TemplatePDO();$revision=hash('sha256',$db->text);
rejectTemplate(fn()=>live2_template_save($db,['id'=>2,'text'=>'tekst','revision'=>$revision]));
rejectTemplate(fn()=>live2_template_save($db,['id'=>1,'text'=>'','revision'=>$revision]));
rejectTemplate(fn()=>live2_template_save($db,['id'=>1,'text'=>'Beste [contactpersoon]','revision'=>$revision]));
$result=live2_template_save($db,['id'=>1,'text'=>'<p>Hallo [contactpersoon], zie [busbriefje]</p>','revision'=>$revision]);
checkTemplate($result['revision']===hash('sha256',$db->text));
rejectTemplate(fn()=>live2_template_save($db,['id'=>1,'text'=>'[contactpersoon] [busbriefje]','revision'=>$revision]));
$db->race=true;
rejectTemplate(fn()=>live2_template_save($db,['id'=>1,'text'=>'[contactpersoon] [busbriefje]','revision'=>$result['revision']]));
checkTemplate($db->text==='Andere tekst');
echo "Template storage and conflict checks passed.\n";

$all=live2_templates($db);
checkTemplate(count($all)===5 && array_column($all,'id')===[1,3,4,5,6]);
checkTemplate($all[0]['text']==='Eerste tekst' && !$all[0]['missing']);
checkTemplate($all[1]['missing'] && $all[1]['revision']===null && $all[1]['text']==='');
echo "All five template slots are visible with only two database rows.\n";
