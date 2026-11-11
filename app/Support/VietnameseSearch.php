<?php
namespace App\Support;
use Illuminate\Support\Str;
final class VietnameseSearch
{
    public static function normalize(string $value): string { return trim(preg_replace('/\s+/u',' ',mb_strtolower(Str::ascii($value, 'vi')))); }
    public static function text(array $values): string {
        return self::normalize(implode(' ', array_map(fn($key)=>(string)($values[$key] ?? ''), ['code','name','phone','email','tax_code','identity_number','address','billing_address','source','campaign_code','region'])));
    }
    public static function apply($query, string $search): void {
        // Literal matching: user-entered wildcard characters are not SQL patterns.
        $text = str_replace(['!','%','_','['], ['!!','!%','!_','!['], self::normalize(trim($search)));
        $phone=preg_match('/^\+?[0-9().\s-]+$/D',trim($search)) ? PhoneNumber::canonical($search) : '';
        $query->where(function($q) use($text,$phone) {
            $q->whereRaw("search_text LIKE ? ESCAPE '!'", ['%'.$text.'%']);
            if(strlen($phone)>=8 && strlen($phone)<=15) {
                // Match old rows too: no search-index rebuild is required after this fix.
                $digits="REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', ''), '(', ''), ')', ''), '.', '')";
                $variants=[$phone];if(str_starts_with($phone,'0'))$variants[]='84'.substr($phone,1);
                $q->orWhereRaw($digits.' IN ('.implode(',',array_fill(0,count($variants),'?')).')',$variants);
            }
        });
    }
}
