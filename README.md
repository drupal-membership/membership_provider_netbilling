# NETbilling Membership Provider

### Example postback payload

Line breaks added for readability.

```
http://localhost:8098/membership_provider_netbilling
    ?Ecom_Cost_Total=10.01
    &form_build_id=form-2cPcl0JlAbWRQgx-Oj-AP24VA-gc7ze7v5ffDS8FgL0
    &form_id=membership_provider_netbilling_buy_button
    &Ecom_Ezic_Recurring_ID=114350668953
    &Ecom_Ezic_Remote_Addr=1.2.3.4
    &Ecom_BillTo_Postal_PostalCode=80205
    &Ecom_Ezic_Security_HashValue_MD5=306ccd7127ea23980377a3dc5243d714
    &Ecom_Ezic_ProofOfPurchase_MD5=9D26B4B6BE2207F5A2ED41F000671370
    &Ecom_Ezic_Membership_UserName=test
    &Ecom_Ezic_TransactionId=114262403227
    &Ecom_BillTo_Postal_CountryCode=US
    &Ecom_Ezic_Membership_PassWord=test123
    &Ecom_Ezic_Recurring_Amount=20.02
    &Ecom_Ezic_Security_HashFields=Ecom_Ezic_AccountAndSitetag%20Ecom_Cost_Total%20Ecom_Receipt_Description%20Ecom_Ezic_Membership_Period
    &Ecom_Ezic_Recurring_Period=add_months%28%28trunc%28sysdate%29%2B0.5%29%2C1%29
    &Ecom_Receipt_Description=A%20great%20offer
    &Ecom_Ezic_TransactionStatus=1
    &Ecom_Ezic_Membership_ID=114350668952
    &Ecom_Ezic_Membership_Period=30.00000
    &Ecom_Ezic_AccountAndSitetag=104901072025%3ATEST
    &op=Join%20with%20Visa%2FMasterCard
    &Ecom_Ezic_Response_AuthCode=999999
    &Ecom_Ezic_Response_StatusSubCode=1
    &Ecom_Ezic_Response_IssueDate=2020-07-04%2020%3A13%3A39
    &Ecom_Ezic_Response_Card_AVSCode=X
    &Ecom_Ezic_Response_StatusCode=1
    &Ecom_Ezic_Response_Card_VerificationCode=M
    &Ecom_Ezic_Response_AuthMessage=TEST%20APPROVED
    &Ecom_Ezic_Response_TransactionID=114262403227
```
