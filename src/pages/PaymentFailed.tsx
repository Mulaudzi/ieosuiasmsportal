import { useNavigate, useSearchParams } from 'react-router-dom';
import { DashboardLayout } from '@/components/layout/DashboardLayout';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { XCircle, RefreshCw, Wallet, MessageSquare, ArrowLeft } from 'lucide-react';

const PaymentFailed = () => {
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  
  const reference = searchParams.get('reference');
  const reason = searchParams.get('reason');
  const cancelled = searchParams.get('cancelled') === '1';
  
  return (
    <DashboardLayout title={cancelled ? "Payment Cancelled" : "Payment Failed"}>
      <div className="flex items-center justify-center min-h-[60vh]">
        <Card className="w-full max-w-lg text-center">
          <CardHeader className="pb-4">
            <div className={`mx-auto mb-4 flex h-20 w-20 items-center justify-center rounded-full ${cancelled ? 'bg-amber-100' : 'bg-red-100'}`}>
              <XCircle className={`h-12 w-12 ${cancelled ? 'text-amber-600' : 'text-red-600'}`} />
            </div>
            <CardTitle className="text-2xl">
              {cancelled ? 'Payment Cancelled' : 'Payment Failed'}
            </CardTitle>
            <CardDescription className="text-base">
              {cancelled 
                ? 'You cancelled the payment process. No charges have been made to your account.'
                : 'Unfortunately, your payment could not be processed. Please try again or use a different payment method.'}
            </CardDescription>
          </CardHeader>
          <CardContent className="space-y-6">
            {reference && (
              <div className="bg-muted/50 rounded-lg p-4">
                <p className="text-sm text-muted-foreground">Reference Number</p>
                <p className="font-mono font-semibold">{reference}</p>
              </div>
            )}
            
            {reason && (
              <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-left">
                <p className="text-sm font-medium text-red-800">Reason</p>
                <p className="text-sm text-red-700 mt-1">{reason}</p>
              </div>
            )}
            
            <div className="bg-muted/50 rounded-lg p-4 text-left">
              <p className="text-sm font-medium mb-2">What you can do:</p>
              <ul className="text-sm text-muted-foreground space-y-1">
                <li>• Check your card details and try again</li>
                <li>• Use a different payment method</li>
                <li>• Ensure you have sufficient funds</li>
                <li>• Contact your bank if the issue persists</li>
              </ul>
            </div>
            
            <div className="flex flex-col gap-3">
              <Button onClick={() => navigate('/wallet')} className="w-full">
                <RefreshCw className="h-4 w-4 mr-2" />
                Try Again
              </Button>
              
              <Button 
                variant="outline" 
                onClick={() => navigate('/wallet')}
                className="w-full"
              >
                <Wallet className="h-4 w-4 mr-2" />
                Back to Wallet
              </Button>
              
              <Button 
                variant="ghost" 
                onClick={() => navigate('/support')}
                className="w-full"
              >
                <MessageSquare className="h-4 w-4 mr-2" />
                Contact Support
              </Button>
            </div>
            
            <Button 
              variant="link" 
              onClick={() => navigate('/dashboard')}
              className="text-muted-foreground"
            >
              <ArrowLeft className="h-4 w-4 mr-2" />
              Return to Dashboard
            </Button>
          </CardContent>
        </Card>
      </div>
    </DashboardLayout>
  );
};

export default PaymentFailed;
