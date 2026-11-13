<?php
namespace App\Services;
use App\Models\SettingModel;
use InvalidArgumentException;
class AppearanceSettingsService
{
    /** Resolve one validated, existing logo for desktop/mobile navigation and settings previews. */
    public static function logoUrl(?array $settings = null): string
    {
        $settings ??= (new SettingModel())->allAssoc();
        $relative = $settings['system_logo'] ?? '';
        if (!is_string($relative) || !preg_match('~^assets/uploads/branding/[a-f0-9]{32}\.png$~D', $relative) || !is_file(FCPATH.$relative)) {
            $relative = 'assets/images/mudnaan-logo.jpeg';
        }
        return base_url($relative).'?v='.filemtime(FCPATH.$relative);
    }

    /** Derive the complete UI palette so hover, borders and soft fills share the chosen color. */
    public static function palette(string $color): array
    {
        if (!preg_match('/^#[a-f0-9]{6}$/i',$color)) $color='#1967d2';
        $rgb=sscanf($color,'#%02x%02x%02x');$result=[];
        foreach([50=>.96,100=>.9,200=>.75,300=>.55,400=>.3,500=>.12,600=>0,700=>-.15,800=>-.3,900=>-.45] as $shade=>$blend) {
            $channels=array_map(static fn($value)=>(int)round($blend>=0?$value+(255-$value)*$blend:$value*(1+$blend)),$rgb);
            $result[$shade]=sprintf('#%02x%02x%02x',...$channels);
        }
        return $result;
    }
    public function save(array $input): void
    {
        $values=[];
        // Product identity is fixed. Ignore legacy system_name fields from older settings forms.
        foreach(['business_name'=>100,'business_address'=>255,'business_phone'=>60,'receipt_header'=>200,'receipt_footer'=>500] as $key=>$max){
            if(!array_key_exists($key,$input))continue;if(!is_scalar($input[$key]))throw new InvalidArgumentException('Enter valid setting values.');$value=trim((string)$input[$key]);
            if(mb_strlen($value)>$max||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/',$value)||$key==='business_name'&&$value==='')throw new InvalidArgumentException('Enter a valid '.str_replace('_',' ',$key).'.');$values[$key]=$value;
        }
        foreach(['brand_color','accent_color','background_color'] as $key)if(isset($input[$key])){if(!is_string($input[$key])||!preg_match('/^#[a-f0-9]{6}$/i',$input[$key]))throw new InvalidArgumentException('Choose a valid color.');$values[$key]=$input[$key];}
        if(isset($input['receipt_style'])){if(!in_array($input['receipt_style'],['classic','compact'],true))throw new InvalidArgumentException('Select a valid receipt style.');$values['receipt_style']=$input['receipt_style'];}
        if(isset($input['thermal_paper_width'])){if(!is_scalar($input['thermal_paper_width'])||!in_array((string)$input['thermal_paper_width'],['58','80'],true))throw new InvalidArgumentException('Select 58mm or 80mm paper.');$values['thermal_paper_width']=(string)$input['thermal_paper_width'];}
        FinancialTransaction::run(function()use($values){(new SettingModel())->saveBatchAssoc($values);AuditService::log('settings.appearance.update','settings','settings',null,after:$values);});
    }
    public function uploadLogo(\CodeIgniter\HTTP\Files\UploadedFile $file): string
    {
        if(!$file->isValid()||$file->getSize()>2*1024*1024||!in_array($file->getMimeType(),['image/png','image/jpeg','image/webp'],true))throw new InvalidArgumentException('Upload a PNG, JPEG, or WebP logo up to 2 MB.');
        $size=@getimagesize($file->getTempName());if(!$size||$size[0]>4096||$size[1]>4096)throw new InvalidArgumentException('Logo dimensions must be at most 4096 × 4096.');
        $image=@imagecreatefromstring(file_get_contents($file->getTempName()));if(!$image)throw new InvalidArgumentException('The logo image could not be read.');
        imagealphablending($image,false);imagesavealpha($image,true);
        $relative='assets/uploads/branding/'.bin2hex(random_bytes(16)).'.png';$path=FCPATH.$relative;
        try{if(!is_dir(dirname($path))&&!mkdir(dirname($path),0755,true))throw new \RuntimeException('Could not create logo directory.');if(!imagepng($image,$path))throw new \RuntimeException('Could not save logo.');FinancialTransaction::run(function()use($relative){(new SettingModel())->setVal('system_logo',$relative);AuditService::log('settings.logo.update','settings','settings',null,after:['system_logo'=>$relative]);});}
        catch(\Throwable $e){if(is_file($path))unlink($path);throw $e;}finally{imagedestroy($image);}
        return $relative;
    }
}
