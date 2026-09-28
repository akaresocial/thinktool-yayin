# thinktool.com.tr — yayın deposu

Bu depo **otomatik oluşturulur** (kaynak ayrı ve özel depodadır). Elle düzenlemeyin.

- `public/` — sitenin kendisi: statik sayfalar + PHP sipariş/ödeme/yönetim uçları (sunucuda web köküne kurulur)
- `_ops/deploy.sh` — sunucuda cron ile çalışan yayın betiği (doğrula → yedekle → kur → canlı test → gerekirse geri dön)
- `_ops/urls.txt` — kurulum sonrası canlı test listesi · `_ops/SHA256SUMS` — dosya sağlama toplamları
- `_ops/enabled` — `1` değilse sunucu kurulum yapmaz

Hiçbir şifre, anahtar, sipariş ya da müşteri bilgisi bu depoda bulunmaz; bunlar yalnızca sunucudaki veritabanındadır.
