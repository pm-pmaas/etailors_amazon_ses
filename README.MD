**Mautic AWS SES plugin with callback**

We have creaed a Mautic 5 plugin for Amazon SES. The plugin is based
on the structure of https://github.com/mabumusa1/ses-plugin and https://github.com/ts-navghane/sparkpost-plugin

***Configuration***

DSN format: **`mautic+ses+api`**

Region could be set in options like:

`Label: region      Value: eu-west-1`

**availble regions:**
us-east-1
us-east-2      
ap-south-1    
ap-northeast-2 
ap-southeast-1 
ap-southeast-2 
ap-northeast-1 
ca-central-1  
eu-central-1   
eu-west-1     
eu-west-2      
sa-east-1      
us-gov-west-1  

**Calback confiruation**

Callback needs to be setup in Amazon SNS on the following url:
https://yourmauticinstance.com/mailer/callback	

_This is my first mauitc plugin so improvements and feedback are absolutely welcome._