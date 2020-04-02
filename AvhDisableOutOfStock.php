<?php
/***
 * AvhDisableOutOfStock
 *
 * Disables article out of stock
 * Started by Cronjob
 * Mail notification if configured
 * Adds a own Mail Template
 *
 * Sources:
 * Mail Template -> https://developers.shopware.com/developers-guide/plugin-guidelines/#adding-e-mail-templates
 * Cronjob -> https://developers.shopware.com/developers-guide/plugin-quick-start/#plugin-cronjob
 *
 */

namespace AvhDisableOutOfStock;

use Shopware\Components\Plugin;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Shopware\Components\Model\ModelManager;
use Shopware\Components\Plugin\Context\InstallContext;
use Shopware\Components\Plugin\Context\UninstallContext;
use Shopware\Models\Mail\Mail;

/**
 * Shopware-Plugin AvhDisableOutOfStock.
 */
class AvhDisableOutOfStock extends Plugin
{

    /**
    * @param ContainerBuilder $container
    */
    public function build(ContainerBuilder $container)
    {
        $container->setParameter('avh_disable_out_of_stock.plugin_dir', $this->getPath());
        parent::build($container);
    }

    public static function getSubscribedEvents()
    {
        return [
            'Shopware_CronJob_DisableOutOfStock' => 'onDisableOutOfStock'
        ];
    }

    public const MAIL_TEMPLATE_NAME = 'AvhDisableOutOfStock';

    public function install(InstallContext $context): void
    {
        $this->installMailTemplate();
    }

    public function uninstall(UninstallContext $context): void
    {
        $this->uninstallMailTemplate();
    }

    public function onDisableOutOfStock(\Shopware_Components_Cron_CronJob $job)
    {

        $config = $this->container->get('shopware.plugin.cached_config_reader')->getByPluginName($this->getName());

        $variantResource = new \Shopware\Components\Api\Resource\Variant();
        $variantResource->setManager(Shopware()->Models());

        $articleResource = new \Shopware\Components\Api\Resource\Article();
        $articleResource->setManager(Shopware()->Models());

        // Config $articleList
        $conf = [
            ['property' => 'inStock','value' => 0],
            ['property' => 'active','value' => true]
        ];

        if($config['lastStock'] == true) {
            array_push($conf,['property' => 'lastStock', 'value' => true]);
        }

        $articleList = $variantResource->getList(0,1000,$conf);

        var_dump($articleList);
        $articles = [];
        $i=0;
        foreach ($articleList['data'] as $article)
        {
            $articles[$i]['name'] = $article['article']['name'];
            $articles[$i]['number'] = $article['number'];
            $i++;

            if($article['kind'] == 1) {
                $articleResource->updateByNumber($article['number'],['active' => false, 'mainDetail' => ['active' => false]]);
            } else {
                $variantResource->updateByNumber($article['number'],['active' => false]);
            }
        }

        #var_dump($articles);

        // Config oder Systemmailadresse
        if (filter_var($config['email'], FILTER_VALIDATE_EMAIL)) {
            $email = $config['email'];
        } else {
            $email = shopware()->Config()->get('mail');
        }

        if($config['sendmail'] == true) {
            $mail = Shopware()->TemplateMail()->createMail(self::MAIL_TEMPLATE_NAME, ['Article' => $articles]);
            $mail->addTo($email);
            $mail->send();
        }

    }

    /**
     * installMailTemplate takes care of creating the new E-Mail-Template
     */
    private function installMailTemplate(): void
    {
        $entityManager = $this->container->get('models');
        $mail = new Mail();

        // After creating an empty instance, some technical info is set
        $mail->setName(self::MAIL_TEMPLATE_NAME);
        $mail->setMailtype(Mail::MAILTYPE_USER);

        // Now the templates basic information can be set
        $mail->setSubject($this->getSubject());
        $mail->setContent($this->getContent());
        $mail->setFromMail('{config name=mail}');
        $mail->setFromName('{config name=shopName}');
        $mail->setIsHtml(true);
        $mail->setContentHtml($this->getContentHtml());

        $mail->setContext(['Article' => [ 0 => ['name' => "Test Artikel", 'number' => 'xyz']]]); // Vars an Template Übergeben, weitere mit komma


        /**
         * Finally the new template can be persisted.
         *
         * transactional is a helper method which wraps the given function
         * in a transaction and executes a rollback if something goes wrong.
         * Any exception that occurs will be thrown again and, since we're in
         * the install method, shown in the backend as a growl message.
         */
        $entityManager->transactional(static function ($em) use ($mail) {
            /** @var ModelManager $em */
            $em->persist($mail);
        });
    }

    /**
     * uninstallMailTemplate takes care of removing the plugin's E-Mail-Template
     */
    private function uninstallMailTemplate(): void
    {
        $entityManager = $this->container->get('models');
        $repo = $entityManager->getRepository(Mail::class);

        // Find the mail-type we created
        $mail = $repo->findOneBy(['name' => self::MAIL_TEMPLATE_NAME]);

        $entityManager->transactional(static function ($em) use ($mail) {
            /** @var ModelManager $em */
            $em->remove($mail);
        });
    }

    private function getSubject(): string
    {
        return 'Automatisch deaktivierte Artikel';
    }

    private function getContent(): string
    {
        /**
         * Notice the string:{...} in the include's file-attribute.
         * This causes the referenced config value to be loaded into
         * a string and passed on as the template's content. This works
         * because the file-attribute can accept any template resource
         * which includes paths to files and several other types as well.
         * For more information about template resources, have a look here:
         * https://www.smarty.net/docs/en/resources.string.tpl
         */
        return <<<'EOD'
{include file="string:{config name=emailheaderplain}"}

{* Content *}
Hallo,

folgende Artikel wurden deaktiviert:

{foreach item=details key=position from=$Article}
{{$position+1}|fill:4}  {$details.number|fill:20}  {$details.name|fill:49} 
{/foreach}

{include file="string:{config name=emailfooterplain}"}
EOD;
    }

    private function getContentHtml(): string
    {
        return <<<'EOD'
<div style="font-family:arial; font-size:12px;">
{include file="string:{config name=emailheaderhtml}"}
    <p>Hallo,</p><br>
    <p>folgende Artikel wurden deaktiviert:</p><br>
            <table width="80%" border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
                <tr>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Pos.</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>SKU</strong></td>
                    <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;">Bezeichnung</td>
                </tr>

                {foreach item=details key=position from=$Article}
                <tr>
                    <td style="border-bottom:1px solid #cccccc;">{$position+1|fill:4} </td>
                    <td style="border-bottom:1px solid #cccccc;">{$details.number|fill:20}</td>
                    <td style="border-bottom:1px solid #cccccc;">
                      {$details.name|wordwrap:80|indent:4}<br>
                    </td>
                </tr>
                {/foreach}

            </table>
{include file="string:{config name=emailfooterhtml}"}
</div>
EOD;
    }

}
