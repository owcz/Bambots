gunzip -c wikidatawiki*-stub-meta-history.xml.gz | ./xml2 | grep '/mediawiki/page/revision/contributor/username=\|/mediawiki/page/revision/contributor/ip=\|/mediawiki/page/revision/comment=' | php navelgazer.php
